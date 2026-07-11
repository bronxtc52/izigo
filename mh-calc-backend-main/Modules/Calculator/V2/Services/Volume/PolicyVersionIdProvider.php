<?php

namespace Modules\Calculator\V2\Services\Volume;

use Illuminate\Contracts\Container\Container;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Throwable;

/**
 * T03: адаптер «policy_version_id для provenance» поверх контракта T01.
 *
 * Amendments MF-5: единственная сигнатура — PolicyVersionResolver::forDate(): PolicyV2
 * (доменный объект, наполняет T01). T03 из него нужен только int id для снапшотов/лотов.
 * До merge T01 (резолвер не забинден / PolicyV2 ещё пустой) — заглушка version_id=1,
 * как предписывает план T03. После merge T01: если PolicyV2 отдаёт id (метод id()
 * или публичное свойство), берём его — код T03 менять не придётся.
 */
class PolicyVersionIdProvider
{
    public const FALLBACK_VERSION_ID = 1;

    public function __construct(private readonly Container $container)
    {
    }

    public function forDate(\DateTimeInterface $at): int
    {
        if (! $this->container->bound(PolicyVersionResolver::class)) {
            return self::FALLBACK_VERSION_ID;
        }

        try {
            $policy = $this->container->make(PolicyVersionResolver::class)->forDate($at);
        } catch (Throwable $e) {
            // Нет активной версии политики (PolicyNotActiveException T01) либо стаб —
            // volume-слой не должен ронять оплату: provenance-фолбэк. Не молча: после
            // merge T01 тихий фолбэк исказил бы provenance снапшотов/лотов.
            \Illuminate\Support\Facades\Log::warning(
                'V2 volumes: PolicyVersionResolver недоступен — provenance-фолбэк version_id=1',
                ['at' => $at->format(DATE_ATOM), 'error' => $e->getMessage()]
            );

            return self::FALLBACK_VERSION_ID;
        }

        if (method_exists($policy, 'id')) {
            return (int) $policy->id();
        }
        if (property_exists($policy, 'id')) {
            return (int) $policy->id;
        }

        return self::FALLBACK_VERSION_ID;
    }
}
