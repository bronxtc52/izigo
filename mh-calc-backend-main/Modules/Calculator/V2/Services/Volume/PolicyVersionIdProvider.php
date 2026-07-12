<?php

namespace Modules\Calculator\V2\Services\Volume;

use Illuminate\Contracts\Container\Container;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Throwable;

/**
 * T03: адаптер «policy_version_id для provenance» поверх контракта T01.
 *
 * Amendments MF-5: единственная сигнатура — PolicyVersionResolver::forDate(): PolicyV2
 * (доменный объект T01, provenance-API — versionId()/configHash(), ревью W1 MF-1).
 * Fallback version_id=1 допустим ТОЛЬКО когда резолвер не забинден или активной
 * версии нет (PolicyNotActiveException) — и всегда с warning в лог.
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
            // volume-слой не должен ронять оплату: provenance-фолбэк. Не молча: тихий
            // фолбэк при активной политике исказил бы provenance снапшотов/лотов.
            \Illuminate\Support\Facades\Log::warning(
                'V2 volumes: PolicyVersionResolver недоступен — provenance-фолбэк version_id=1',
                ['at' => $at->format(DATE_ATOM), 'error' => $e->getMessage()]
            );

            return self::FALLBACK_VERSION_ID;
        }

        // Контракт MF-5: доменный объект PolicyV2, provenance — строго versionId().
        return $policy->versionId();
    }
}
