<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\V2\CutoverLog;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\V2\Services\Cutover\BronzeTariffCutoverService;
use Modules\Calculator\V2\Services\Cutover\LedgerReconciliationService;
use Modules\Calculator\V2\Services\Cutover\OpeningBalanceMigrationService;

/**
 * mh-full-plan T15 (W6): data-cutover V1→ОС V2. НЕ авто на деплое, запускается
 * человеком на проде. По умолчанию --dry-run (только план, без записи); --commit —
 * реальный перенос под ACTIVATION_LOCK в одной транзакции.
 *
 * Делает ДВЕ вещи и НИЧЕГО больше:
 *   1) Bronze → 100 PV / 100 USDT (решение владельца);
 *   2) денежный main-баланс каждого партнёра → бессрочный opening-лот ОС (reclass).
 * PV/тиры/статусы НЕ бэкфилит (старт с нуля). Фиче-флаг mh_plan_v2_engine НЕ трогает —
 * флип движка делает координатор отдельным шагом под owner-гейтом (PROD-гейт).
 *
 * Прекондишен --commit: сверка ledger green (trial balance 0, кэши == свёртка, лоты ==
 * кэш). Дрейф → abort без единой проводки. Идемпотентно: повтор не задваивает лоты.
 */
class CutoverMigrateCommand extends Command
{
    protected $signature = 'calc-v2:cutover-migrate
        {--commit : выполнить реальный перенос (по умолчанию dry-run — только план)}
        {--actor= : кто запускает команду (для аудита v2_cutover_log)}';

    protected $description = 'V2 cutover: Bronze→100 + перенос main-баланса на ОС opening (dry-run по умолчанию)';

    public function handle(
        BronzeTariffCutoverService $bronze,
        OpeningBalanceMigrationService $opening,
        LedgerReconciliationService $reconciliation,
        ActivationService $activation,
    ): int {
        $commit = (bool) $this->option('commit');
        $actor = $this->option('actor') ?: 'cli';

        $this->info($commit ? '=== CUTOVER MIGRATE — COMMIT (реальный перенос) ===' : '=== CUTOVER MIGRATE — DRY-RUN (без записи) ===');

        // --- Bronze тариф ---
        $bronzeCurrent = $bronze->current();
        if ($bronzeCurrent === null) {
            $this->warn('Тариф Bronze (TARIFF-BRONZE) не найден в каталоге — правка тарифа пропущена.');
        } else {
            $this->line(sprintf(
                'Bronze: сейчас %d PV / %d центов → цель %d PV / %d центов%s',
                $bronzeCurrent['pv'], $bronzeCurrent['price_usdt_cents'],
                BronzeTariffCutoverService::TARGET_PV, BronzeTariffCutoverService::TARGET_PRICE_CENTS,
                $bronze->needsUpdate() ? '' : ' (уже применено)',
            ));
        }

        // --- План переноса баланса ---
        $plan = $opening->plan();
        $projected = $opening->projectedTotalCents();
        $this->newLine();
        $this->line('План переноса main-баланс → ОС opening (бессрочный лот):');
        $this->table(
            ['member_id', 'available_cents', 'уже перенесён'],
            array_map(fn ($r) => [$r['member_id'], $r['available_cents'], $r['already_migrated'] ? 'да' : 'нет'], $plan),
        );
        $this->line(sprintf('Итого к переносу: %d центов по %d партнёрам.', $projected, count($plan)));

        // --- Сверка ledger (прекондишен) ---
        $recon = $reconciliation->check();
        $this->newLine();
        $this->line(sprintf(
            'Сверка ledger: trial balance Δ=%d, небалансных tx=%d, дрейф кэша=%d, дрейф лотов=%d → %s',
            $recon['trial_balance']['delta'], count($recon['unbalanced_tx']),
            count($recon['cache_drift']), count($recon['lot_drift']),
            $recon['ok'] ? 'OK' : 'ПРОВАЛ',
        ));

        if (! $commit) {
            CutoverLog::query()->create([
                'action' => CutoverLog::ACTION_PHASE,
                'phase' => CutoverLog::PHASE_DRY_RUN,
                'actor' => $actor,
                'dry_run' => true,
                'amount_cents' => $projected,
                'detail' => ['bronze' => $bronzeCurrent, 'members' => count($plan), 'reconciliation_ok' => $recon['ok']],
            ]);
            $this->newLine();
            $this->info('DRY-RUN завершён. Записи не менялись. Для реального переноса: --commit (флаг движка НЕ флипается).');

            return self::SUCCESS;
        }

        if (! $recon['ok']) {
            $this->error('ABORT: сверка ledger не сошлась — перенос НЕ выполнен (ни одной проводки). Разберите дрейф до cutover.');
            CutoverLog::query()->create([
                'action' => CutoverLog::ACTION_RECONCILIATION,
                'phase' => CutoverLog::PHASE_PRE,
                'actor' => $actor,
                'dry_run' => false,
                'detail' => ['aborted' => true, 'reconciliation' => $recon],
            ]);

            return self::FAILURE;
        }

        // --- COMMIT: одна транзакция под ACTIVATION_LOCK ---
        $result = DB::transaction(function () use ($bronze, $opening, $activation, $actor) {
            // Единый порядок локов с активациями/закрытиями периодов V2 (0x12916001).
            $activation->acquireActivationLock();

            $bronzeResult = $bronze->apply();
            if ($bronzeResult !== null && $bronzeResult['before'] !== $bronzeResult['after']) {
                CutoverLog::query()->create([
                    'action' => CutoverLog::ACTION_BRONZE_TARIFF,
                    'phase' => CutoverLog::PHASE_MIGRATED,
                    'actor' => $actor,
                    'dry_run' => false,
                    'detail' => $bronzeResult,
                ]);
            }

            $migration = $opening->commitAll();
            foreach ($migration['entries'] as $entry) {
                CutoverLog::query()->create([
                    'action' => CutoverLog::ACTION_OPENING,
                    'phase' => CutoverLog::PHASE_MIGRATED,
                    'actor' => $actor,
                    'dry_run' => false,
                    'member_id' => $entry['member_id'],
                    'amount_cents' => $entry['amount_cents'],
                    'tx_id' => $entry['tx_id'],
                ]);
            }

            CutoverLog::query()->create([
                'action' => CutoverLog::ACTION_PHASE,
                'phase' => CutoverLog::PHASE_MIGRATED,
                'actor' => $actor,
                'dry_run' => false,
                'amount_cents' => $migration['total_cents'],
                'detail' => ['migrated' => $migration['migrated'], 'skipped' => $migration['skipped']],
            ]);

            return $migration;
        });

        // --- Пост-проверка сверки ---
        $post = $reconciliation->check();
        $this->newLine();
        $this->info(sprintf(
            'COMMIT завершён: перенесено %d партнёров (%d центов), пропущено %d. Пост-сверка: %s.',
            $result['migrated'], $result['total_cents'], $result['skipped'], $post['ok'] ? 'OK' : 'ПРОВАЛ',
        ));
        $this->warn('Флаг mh_plan_v2_engine НЕ включён этой командой — money-cutover (флип движка) выполняет координатор отдельно под owner-гейтом.');

        return $post['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
