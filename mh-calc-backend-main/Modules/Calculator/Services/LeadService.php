<?php

namespace Modules\Calculator\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Lead;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use RuntimeException;

/**
 * Жизненный цикл лида (до первой покупки). Лид вне бинар-дерева; спонсора можно менять,
 * пока окно (lead_window_days) не истекло. При первой подтверждённой оплате лид
 * промоутится в Member через MemberService (постановка в дерево под замкнутого спонсора).
 */
class LeadService
{
    /**
     * Статусы платежа, при которых лида удалять НЕЛЬЗЯ: инвойс выдан (external_ref), деньги
     * могут прийти on-chain или уже пришли, но платёж ещё не финализирован. expired входит:
     * TTL мог съесть pending при недоступном индексаторе, а recheck/пере-опрос ещё вернёт
     * деньги — тогда markPaid должен найти лида. failed/paid НЕ защищают (failed = денег нет
     * by design; paid = лид уже промоутнут и удалён штатно).
     */
    private const UNSETTLED_PAYMENT_STATUSES = [
        Payment::STATUS_CREATED,
        Payment::STATUS_PENDING,
        Payment::STATUS_EXPIRED,
    ];

    /**
     * Ключ pg_advisory_xact_lock жизненного цикла лида: сериализует экспирацию/открепление
     * лида (expireDue, ветка удаления в attachOrReattach) с промоушном при оплате (promote,
     * OrderService::markPaid). Без него TOCTOU: экспирация читает «нет платежа», а параллельная
     * оплата в этот момент создаёт платёж / промоутит лида — удаление осиротит заказ/платёж
     * (FK nullOnDelete) → markPaid не найдёт участника → деньги без фулфилмента.
     *
     * Значение ОТЛИЧНО от ACTIVATION_LOCK_KEY (0x12916001) и V2-ключей — это отдельный лок.
     * Порядок захвата строго lead-lifecycle → activation (markPaid берёт этот лок ПЕРВЫМ, до
     * activate()): единый порядок без цикла, иначе дедлок с пересчётом сети.
     */
    public const LEAD_LIFECYCLE_LOCK_KEY = 0x12916002;

    public function __construct(private readonly MemberService $members)
    {
    }

    /**
     * Взять транзакционный advisory-lock жизненного цикла лида (блокирующе). Публичный: внешние
     * транзакции (OrderService::markPaid) берут его ПЕРВЫМ действием, до activation-лока, храня
     * единый порядок захвата. Вне транзакции вызывать нельзя (pg_advisory_xact_lock требует её).
     */
    public function acquireLeadLock(): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?)', [self::LEAD_LIFECYCLE_LOCK_KEY]);
    }

    /**
     * Создать лида или перепривязать к новому спонсору (last-click-wins) в пределах окна.
     * Возвращает лида либо null, если привязать не к кому (нет валидного ref и нет лида).
     * Используется на входе Mini App (resolveIdentity) — спонсор из start_param реф-ссылки.
     */
    public function attachOrReattach(
        int $telegramId,
        ?string $name,
        ?string $username,
        ?string $sponsorRef,
        ?string $language,
    ): ?Lead {
        $existing = Lead::query()->where('telegram_id', $telegramId)->first();
        // Истёкший лид — открепляем (свободен): следующий переход привяжет заново.
        // НО не трогаем, если по нему висит незавершённый платёж (чекаут в полёте) — иначе FK
        // nullOnDelete осиротит платёж и markPaid не найдёт кому активировать. TOCTOU-safe:
        // проверка платежа и удаление — одним условным DELETE под lead-lifecycle-локом, чтобы
        // параллельная оплата (создание платежа / промоушн) не проскочила в окне «проверил—удаляю».
        if ($existing !== null && $existing->isExpired()) {
            $existingId = $existing->id;
            $deleted = DB::transaction(function () use ($existingId) {
                $this->acquireLeadLock();

                // Условный DELETE: удаляем ТОЛЬКО если ещё истёкший И без незавершённого платежа.
                // Перепроверки expires_at/NOT EXISTS — под локом и в одном стейтменте (атомарно).
                return Lead::query()
                    ->whereKey($existingId)
                    ->where('expires_at', '<', now())
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('payments')
                        ->whereColumn('payments.lead_id', 'leads.id')
                        ->whereIn('status', self::UNSETTLED_PAYMENT_STATUSES))
                    ->delete();
            });

            if ($deleted > 0) {
                $existing = null;
            } else {
                // Не удалён: защищён незавершённым платежом, продлён или уже промоутнут
                // параллельно. Перечитываем актуальное состояние (мог исчезнуть → null).
                $existing = Lead::query()->where('telegram_id', $telegramId)->first();
                if ($existing !== null) {
                    return $existing; // защищён/актуален — не пере-привязываем истёкшего под платежом
                }
            }
        }

        $sponsor = $this->resolveSponsor($sponsorRef);

        if ($existing === null) {
            if ($sponsor === null) {
                return null; // некого назначить спонсором — пусть откроет по реф-ссылке
            }

            try {
                return Lead::query()->create([
                    'telegram_id' => $telegramId,
                    'telegram_username' => $username,
                    'language' => $language,
                    'name' => $name,
                    'sponsor_id' => $sponsor->id,
                    'expires_at' => now()->addDays($this->windowDays()),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Гонка: параллельный первый заход уже создал лида с тем же telegram_id.
                $existing = Lead::query()->where('telegram_id', $telegramId)->first();
                if ($existing === null) {
                    throw new RuntimeException('Не удалось создать лида');
                }
            }
        }

        // Лид уже есть: last-click-wins — переход по ДРУГОЙ валидной рефке меняет спонсора
        // и сбрасывает окно. Освежаем профильные поля из Telegram.
        if ($sponsor !== null && $sponsor->id !== $existing->sponsor_id) {
            $existing->sponsor_id = $sponsor->id;
            $existing->expires_at = now()->addDays($this->windowDays());
        }
        $existing->name = $name ?? $existing->name;
        $existing->telegram_username = $username ?? $existing->telegram_username;
        $existing->language = $language ?? $existing->language;
        $existing->save();

        return $existing;
    }

    /**
     * Явная смена спонсора лида (кнопка в Mini App). Запрещено по истечении окна и при
     * неизвестном/самореферальном ref. Сбрасывает окно заново.
     */
    public function changeSponsor(Lead $lead, string $refCode): Lead
    {
        if ($lead->isExpired()) {
            throw new RuntimeException('Срок привязки истёк — откройте по новой реф-ссылке');
        }

        $sponsor = $this->resolveSponsor($refCode);
        if ($sponsor === null) {
            throw new RuntimeException('Спонсор с таким кодом не найден');
        }
        if ((int) $sponsor->telegram_id === (int) $lead->telegram_id) {
            throw new RuntimeException('Нельзя выбрать самого себя спонсором');
        }

        $lead->sponsor_id = $sponsor->id;
        $lead->expires_at = now()->addDays($this->windowDays());
        $lead->save();

        return $lead;
    }

    /**
     * Промоушн лида в Member при первой подтверждённой оплате: создаёт участника и ставит
     * его в бинар-дерево под замкнутого спонсора (через MemberService::registerTelegram —
     * тот сам генерит ref_code, ставит status=registered и спилловерит). Запись лида
     * удаляется. Вызывается ВНУТРИ платёжной транзакции (OrderService::markPaid) → атомарно.
     */
    public function promote(Lead $lead): Member
    {
        // Первым действием — lead-lifecycle-лок: сериализуем промоушн с экспирацией лида,
        // чтобы параллельный expireDue не удалил лида между промоушном и переносом заказов.
        // Идёт ВНУТРИ транзакции markPaid (advisory-xact-лок держится до commit); повторный
        // захват из markPaid безвреден (тот же ключ). Единый порядок: lead-lifecycle → activation.
        $this->acquireLeadLock();

        $member = $this->members->registerTelegram(
            $lead->telegram_id,
            $lead->name,
            $lead->telegram_username,
            $lead->sponsor?->ref_code,
            $lead->language,
        );

        // Перенести ВСЕ заказы/платежи лида на участника ДО удаления записи. Иначе FK
        // nullOnDelete обнулит lead_id у всех строк, и второй оплаченный заказ лида осиротеет
        // (markPaid увидит member_id=null && lead_id=null и не сможет активировать).
        Order::query()->where('lead_id', $lead->id)->update(['member_id' => $member->id, 'lead_id' => null]);
        Payment::query()->where('lead_id', $lead->id)->update(['member_id' => $member->id, 'lead_id' => null]);

        $lead->delete();

        return $member;
    }

    /**
     * Открепить просроченных лидов (шедулер leads:expire). НЕ трогаем лидов с незавершённым
     * платежом (created|pending|expired) — у них чекаут в полёте либо деньги могли прийти
     * on-chain, а удаление осиротит заказ/платёж (FK nullOnDelete → markPaid не найдёт лида,
     * recheck вечно падал бы). expired защищаем осознанно: TTL мог съесть pending при
     * недоступном индексаторе, а пере-опрос ещё вернёт деньги.
     */
    public function expireDue(): int
    {
        // ОДИН атомарный условный DELETE с коррелированным NOT EXISTS по неоплаченным платежам —
        // вместо прежнего двухшагового stale-snapshot (pluck busy → whereNotIn->delete), где между
        // снимком и удалением параллельная оплата могла создать платёж / промоутить лида, и лид
        // удалялся, осиротив заказ/платёж (потеря денег). NOT EXISTS вычисляется в момент DELETE,
        // а не по устаревшему снимку. Первым действием — lead-lifecycle-лок (сериализация с promote/
        // markPaid), всё в транзакции.
        return DB::transaction(function () {
            $this->acquireLeadLock();

            return Lead::query()
                ->where('expires_at', '<', now())
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn('payments.lead_id', 'leads.id')
                    ->whereIn('status', self::UNSETTLED_PAYMENT_STATUSES))
                ->delete();
        });
    }

    private function resolveSponsor(?string $refCode): ?Member
    {
        return $refCode ? Member::query()->where('ref_code', $refCode)->first() : null;
    }

    private function hasUnsettledPayment(int $leadId): bool
    {
        return Payment::query()
            ->where('lead_id', $leadId)
            ->whereIn('status', self::UNSETTLED_PAYMENT_STATUSES)
            ->exists();
    }

    private function windowDays(): int
    {
        return max(1, (int) config('calculator.lead_window_days', 7));
    }
}
