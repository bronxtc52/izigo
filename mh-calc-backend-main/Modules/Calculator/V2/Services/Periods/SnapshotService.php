<?php

namespace Modules\Calculator\V2\Services\Periods;

use Illuminate\Contracts\Foundation\Application;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Domain\CalcSnapshot;

/**
 * V2 T04: сборка immutable-снапшота входов прогона. Базовые секции: period
 * (границы/tz), policy (policy_version_id + config_hash через контракт T01),
 * payments (манифест оплат окна по paid_at: ids + суммы центов — детерминированный
 * порядок по id). Close-steps T06/T09/T11 расширяют СВОИ секции через addSection()
 * ДО freeze; после freeze снапшот неизменяем (guard в модели).
 *
 * payload_hash = sha256 канонического JSON (рекурсивная сортировка ключей, без
 * недетерминированных значений) — два прогона на идентичных входах дают
 * одинаковый hash (ARCH-NFR-01).
 */
class SnapshotService
{
    /** @var array<string, array> секции, добавленные шагами до freeze */
    private array $pendingSections = [];

    public function __construct(private readonly Application $app)
    {
    }

    /** Сбросить накопленные секции (оркестратор — перед сбором секций нового run'а). */
    public function reset(): void
    {
        $this->pendingSections = [];
    }

    /** Добавить секцию снапшота (close-steps; до freeze текущего run'а). */
    public function addSection(string $name, array $data): void
    {
        if (isset($this->pendingSections[$name])) {
            throw new \LogicException("Секция снапшота '{$name}' уже добавлена.");
        }
        $this->pendingSections[$name] = $data;
    }

    /** Заморозить снапшот прогона. Вызывается оркестратором ровно один раз на run. */
    public function freeze(CalcRun $run, CalcPeriod $period): CalcSnapshot
    {
        $payload = array_merge($this->baseSections($period), $this->pendingSections);
        $this->pendingSections = [];

        $snapshot = CalcSnapshot::query()->create([
            'run_id' => $run->id,
            'payload' => $payload,
            'payload_hash' => self::hash($payload),
            'created_at' => now(),
        ]);

        $run->update(['snapshot_id' => $snapshot->id]);

        return $snapshot;
    }

    /** Канонический sha256 массива: рекурсивная сортировка ключей, стабильный JSON. */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function baseSections(CalcPeriod $period): array
    {
        return [
            'period' => [
                'type' => $period->period_type,
                'code' => $period->code,
                'starts_at' => $period->starts_at->toIso8601ZuluString(),
                'ends_at' => $period->ends_at->toIso8601ZuluString(),
                'timezone' => $period->timezone,
            ],
            'policy' => $this->policySection($period),
            'payments' => $this->paymentsManifest($period),
        ];
    }

    /**
     * policy_version_id + config_hash из контракта T01 (MF-5) — реальный API
     * PolicyV2::versionId()/configHash() (ревью W1 MF-2). null-поля — только когда
     * резолвер не забинден или активной версии нет (T15 backfill).
     */
    private function policySection(CalcPeriod $period): array
    {
        $section = [
            'policy_version_id' => $period->policy_version_id,
            'config_hash' => null,
        ];

        if ($this->app->bound(PolicyVersionResolver::class)) {
            try {
                $policy = $this->app->make(PolicyVersionResolver::class)->forDate($period->starts_at);
                $section['policy_version_id'] = $policy->versionId();
                $section['config_hash'] = $policy->configHash();
            } catch (\Throwable) {
                // активной версии нет — секция остаётся с null (T15 backfill)
            }
        }

        return $section;
    }

    /** Манифест оплат окна [starts_at, ends_at) по paid_at — вход всех расчётов. */
    private function paymentsManifest(CalcPeriod $period): array
    {
        return Payment::query()
            ->where('status', Payment::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $period->starts_at)
            ->where('paid_at', '<', $period->ends_at)
            ->orderBy('id')
            ->get(['id', 'order_id', 'member_id', 'purpose', 'amount_cents'])
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'order_id' => $p->order_id,
                'member_id' => $p->member_id,
                'purpose' => $p->purpose,
                'amount_cents' => $p->amount_cents,
            ])
            ->all();
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        return array_map(self::canonicalize(...), $value);
    }
}
