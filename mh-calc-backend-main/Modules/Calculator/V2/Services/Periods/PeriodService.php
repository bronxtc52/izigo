<?php

namespace Modules\Calculator\V2\Services\Periods;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\PeriodWindow;

/**
 * V2 T04: жизненный цикл строк v2_calc_periods — ленивое идемпотентное создание
 * (insertOrIgnore по UNIQUE(period_type, code)), поиск по коду и единый guard
 * «закрытый период неизменяем» (assertOpen) для всех V2-постингов T06–T11.
 */
class PeriodService
{
    public function __construct(
        private readonly PeriodCalendar $calendar,
        private readonly Application $app,
    ) {
    }

    /** Идемпотентно создать строку периода для окна (повтор — та же строка). */
    public function ensure(PeriodWindow $window): CalcPeriod
    {
        $now = now();
        CalcPeriod::query()->insertOrIgnore([
            'period_type' => $window->type,
            'code' => $window->code,
            'starts_at' => $window->startsAt,
            'ends_at' => $window->endsAt,
            'timezone' => 'UTC',
            'status' => CalcPeriod::STATUS_OPEN,
            'policy_version_id' => $this->resolvePolicyVersionId($window),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return CalcPeriod::query()
            ->where('period_type', $window->type)
            ->where('code', $window->code)
            ->firstOrFail();
    }

    /** Строка периода по коду (создаётся, если ещё не существует). */
    public function ensureByCode(string $code): CalcPeriod
    {
        return $this->ensure($this->calendar->fromCode($code));
    }

    public function findByCode(string $code): ?CalcPeriod
    {
        $window = $this->calendar->fromCode($code);

        return CalcPeriod::query()
            ->where('period_type', $window->type)
            ->where('code', $window->code)
            ->first();
    }

    /**
     * Идемпотентно создать текущие + следующие периоды всех трёх типов
     * плюс catch-up назад: от конца последнего существующего периода типа до «сейчас»
     * (устойчивость к простоям планировщика — просроченные окна получают строки
     * и будут закрыты half-month/month-close командами).
     */
    public function ensureCalendar(\DateTimeInterface $now): int
    {
        $created = 0;
        foreach ([CalcPeriod::TYPE_HALF_MONTH, CalcPeriod::TYPE_MONTH, CalcPeriod::TYPE_QUARTER] as $type) {
            $current = $this->calendar->windowFor($type, $now);

            // Catch-up: подтянуть пропущенные окна между последним известным и текущим.
            $window = $this->backfillStart($type, $current);
            $guard = 0;
            while (true) {
                $before = CalcPeriod::query()->where('period_type', $type)->where('code', $window->code)->exists();
                $this->ensure($window);
                $created += $before ? 0 : 1;
                if ($window->code === $current->code || ++$guard > 100) {
                    break;
                }
                $window = $this->calendar->nextOf($window);
            }

            // Следующее окно — чтобы границы были видны заранее (admin/отчёты).
            $next = $this->calendar->nextOf($current);
            if (! CalcPeriod::query()->where('period_type', $type)->where('code', $next->code)->exists()) {
                $this->ensure($next);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Guard денег: период обязан быть open. Все V2-постинги (T06–T11) зовут его
     * перед проводками; шаги ВНУТРИ пайплайна закрытия работают при status=closing —
     * для них $allowClosing=true передаёт оркестратор.
     */
    public function assertOpen(CalcPeriod $period, bool $allowClosing = false): void
    {
        $allowed = $allowClosing
            ? [CalcPeriod::STATUS_OPEN, CalcPeriod::STATUS_CLOSING]
            : [CalcPeriod::STATUS_OPEN];

        if (! in_array($period->status, $allowed, true)) {
            throw new ClosedPeriodException(
                "Период {$period->code} имеет статус {$period->status}: изменения запрещены (только корректирующие проводки T12)."
            );
        }
    }

    /**
     * policy_version_id на starts_at через контракт T01 (MF-5). До merge T01 резолвер
     * не забинден (или активной версии нет) → null; целостность добьёт backfill T15.
     */
    private function resolvePolicyVersionId(PeriodWindow $window): ?int
    {
        if (! $this->app->bound(PolicyVersionResolver::class)) {
            return null;
        }

        try {
            $policy = $this->app->make(PolicyVersionResolver::class)->forDate($window->startsAt);

            return $policy->id ?? null;
        } catch (\Throwable) {
            return null; // активной версии политики нет — период создаём без привязки
        }
    }

    /**
     * Откуда начинать backfill: конец последнего известного периода; при пустой
     * таблице — предыдущее окно (первый запуск после включения флага видит и
     * только что истёкшее окно, а не только текущее).
     */
    private function backfillStart(string $type, PeriodWindow $current): PeriodWindow
    {
        $latest = CalcPeriod::query()
            ->where('period_type', $type)
            ->orderByDesc('ends_at')
            ->first();

        if ($latest === null) {
            return $this->calendar->previousOf($current);
        }

        if ($latest->ends_at >= $current->startsAt) {
            return $current;
        }

        return $this->calendar->windowFor($type, $latest->ends_at);
    }
}
