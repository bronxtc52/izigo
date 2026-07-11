<?php

namespace Modules\Calculator\V2\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Calculator\Models\PolicyVersion;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\Policy\PolicyV2Factory;

/**
 * T01: жизненный цикл версий политики V2 (v2_policy_versions) + резолвер по дате
 * события (реализация контракта {@see PolicyVersionResolver}, amendments MF-5).
 *
 * Правила:
 *  - мутабелен только draft; активация one-step owner-activate (MF-8, без APPROVED);
 *  - activate: транзакция + lockForUpdate ВСЕХ строк (сериализация конкурентных
 *    активаций — деньги зависят от единственности версии), повторная валидация
 *    конфига, valid_from >= now (retro — только явным флагом для cutover T15),
 *    автозакрытие предыдущей active (valid_to = new valid_from, status = retired);
 *  - инвариант: максимум одна строка active (у неё valid_to IS NULL);
 *  - resolveForDate: полуинтервал [valid_from, valid_to), retired-версии остаются
 *    резолвабельными для исторических дат; нет версии — PolicyNotActiveException;
 *  - аудит create/update/activate/retire — В ТОЙ ЖЕ транзакции (AuditLogService).
 */
class PolicyVersionService implements PolicyVersionResolver
{
    /** per-request кэш резолва (сбрасывается мутациями). */
    private array $resolveCache = [];

    public function __construct(
        private readonly PolicyConfigValidator $validator,
        private readonly PolicyV2Factory $factory,
        private readonly AuditLogService $audit,
    ) {
    }

    // --- PolicyVersionResolver (контракт волны) ---

    public function forDate(\DateTimeInterface $at): PolicyV2
    {
        // Ключ кэша — UTC-instant (nice-to-have ревью W1: один момент времени в разных
        // таймзонах не должен давать разные записи кэша/резолвы).
        $key = CarbonImmutable::instance(\DateTime::createFromInterface($at))->utc()->format('Y-m-d H:i:s.u');
        if (isset($this->resolveCache[$key])) {
            return $this->resolveCache[$key];
        }

        $row = PolicyVersion::query()
            ->whereIn('status', [PolicyVersion::STATUS_ACTIVE, PolicyVersion::STATUS_RETIRED])
            ->whereNotNull('valid_from')
            ->where('valid_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>', $at);
            })
            ->orderByDesc('valid_from')
            ->first();

        if ($row === null) {
            throw PolicyNotActiveException::forDate($at);
        }

        return $this->resolveCache[$key] = $this->factory->fromModel($row);
    }

    public function current(): PolicyV2
    {
        return $this->forDate(now()->toImmutable());
    }

    // --- жизненный цикл ---

    public function createDraft(string $code, array $config, ?int $actorId, ?string $notes = null, int $schemaVersion = 1): PolicyVersion
    {
        $clean = $this->validator->validate($config);
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 64) {
            throw new InvalidArgumentException('code: непустая строка до 64 символов');
        }

        return DB::transaction(function () use ($code, $clean, $actorId, $notes, $schemaVersion) {
            if (PolicyVersion::query()->where('code', $code)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException("Версия с кодом {$code} уже существует");
            }

            $version = PolicyVersion::query()->create([
                'code' => $code,
                'status' => PolicyVersion::STATUS_DRAFT,
                'schema_version' => $schemaVersion,
                'config' => $clean,
                'config_hash' => DefaultPolicyConfig::canonicalHash($clean),
                'notes' => $notes,
                'created_by' => $actorId,
            ]);

            $this->audit->record($actorId, 'policy_version.create', 'policy_version', $version->id, null, $this->auditView($version));
            $this->resolveCache = [];

            return $version;
        });
    }

    public function updateDraft(int $id, array $config, ?int $actorId, ?string $notes = null): PolicyVersion
    {
        $clean = $this->validator->validate($config);

        return DB::transaction(function () use ($id, $clean, $actorId, $notes) {
            /** @var PolicyVersion $version */
            $version = PolicyVersion::query()->lockForUpdate()->findOrFail($id);
            if ($version->status !== PolicyVersion::STATUS_DRAFT) {
                throw new InvalidArgumentException("Версия {$version->code} не draft — активные/retired версии immutable");
            }

            $before = $this->auditView($version);
            $version->update([
                'config' => $clean,
                'config_hash' => DefaultPolicyConfig::canonicalHash($clean),
                'notes' => $notes ?? $version->notes,
            ]);

            $this->audit->record($actorId, 'policy_version.update', 'policy_version', $version->id, $before, $this->auditView($version));
            $this->resolveCache = [];

            return $version;
        });
    }

    /**
     * Активировать draft. $validFrom по умолчанию = now; прошлое допустимо только
     * с $allowRetro (cutover T15). Предыдущая active закрывается (valid_to = new
     * valid_from) и уходит в retired — её интервал остаётся резолвабельным.
     */
    public function activate(int $id, ?int $actorId, ?\DateTimeInterface $validFrom = null, bool $allowRetro = false): PolicyVersion
    {
        return DB::transaction(function () use ($id, $actorId, $validFrom, $allowRetro) {
            // Лок всех строк: сериализует конкурентные активации (вторая транзакция
            // ждёт и увидит уже изменённое состояние).
            $rows = PolicyVersion::query()->orderBy('id')->lockForUpdate()->get();

            /** @var ?PolicyVersion $version */
            $version = $rows->firstWhere('id', $id);
            if ($version === null) {
                throw new InvalidArgumentException("Версия #{$id} не найдена");
            }
            if ($version->status !== PolicyVersion::STATUS_DRAFT) {
                throw new InvalidArgumentException("Версия {$version->code} уже {$version->status} — активировать можно только draft");
            }

            // Повторная валидация: активируется только валидный конфиг.
            $this->validator->validate((array) $version->config);

            $from = CarbonImmutable::parse($validFrom ?? now());
            if (!$allowRetro && $from->lt(now()->subMinute())) {
                throw new InvalidArgumentException('valid_from в прошлом — retro-активация только явным флагом (cutover T15)');
            }

            $current = $rows->first(
                fn (PolicyVersion $r) => $r->status === PolicyVersion::STATUS_ACTIVE && $r->valid_to === null,
            );
            if ($current !== null) {
                if ($current->valid_from !== null && $from->lte(CarbonImmutable::parse($current->valid_from))) {
                    throw new InvalidArgumentException('Интервал пересекается с действующей версией ' . $current->code);
                }
                $currentBefore = $this->auditView($current);
                $current->update(['status' => PolicyVersion::STATUS_RETIRED, 'valid_to' => $from]);
                $this->audit->record($actorId, 'policy_version.supersede', 'policy_version', $current->id, $currentBefore, $this->auditView($current));
            }

            // Непересечение с закрытыми интервалами (retired): [valid_from, valid_to) не должен накрывать $from.
            $overlap = $rows->first(
                fn (PolicyVersion $r) => $r->id !== ($current->id ?? null)
                    && $r->id !== $version->id
                    && $r->status !== PolicyVersion::STATUS_DRAFT
                    && $r->valid_from !== null
                    && $r->valid_to !== null
                    && CarbonImmutable::parse($r->valid_from)->lte($from)
                    && CarbonImmutable::parse($r->valid_to)->gt($from),
            );
            if ($overlap !== null) {
                throw new InvalidArgumentException('Интервал пересекается с версией ' . $overlap->code);
            }

            $before = $this->auditView($version);
            $version->update([
                'status' => PolicyVersion::STATUS_ACTIVE,
                'valid_from' => $from,
                'valid_to' => null,
                'activated_at' => now(),
                'activated_by' => $actorId,
            ]);

            $this->audit->record($actorId, 'policy_version.activate', 'policy_version', $version->id, $before, $this->auditView($version));
            $this->resolveCache = [];

            return $version;
        });
    }

    /**
     * Вывести версию из оборота: draft → retired (отброшенный черновик);
     * active → retired с valid_to = now (с этого момента активной версии НЕТ —
     * V2-расчёты остановятся fail-closed до активации новой).
     */
    public function retire(int $id, ?int $actorId): PolicyVersion
    {
        return DB::transaction(function () use ($id, $actorId) {
            /** @var PolicyVersion $version */
            $version = PolicyVersion::query()->lockForUpdate()->findOrFail($id);
            if ($version->status === PolicyVersion::STATUS_RETIRED) {
                throw new InvalidArgumentException("Версия {$version->code} уже retired");
            }

            $before = $this->auditView($version);
            $validTo = $version->valid_to;
            if ($version->status === PolicyVersion::STATUS_ACTIVE) {
                // Ещё не вступившая в силу версия закрывается пустым интервалом
                // [valid_from, valid_from) — «никогда не действовала».
                $from = $version->valid_from ? CarbonImmutable::parse($version->valid_from) : null;
                $validTo = $from !== null && $from->gt(now()) ? $from : now();
            }
            $version->update([
                'status' => PolicyVersion::STATUS_RETIRED,
                'valid_to' => $validTo,
            ]);

            $this->audit->record($actorId, 'policy_version.retire', 'policy_version', $version->id, $before, $this->auditView($version));
            $this->resolveCache = [];

            return $version;
        });
    }

    // --- чтение для админки ---

    /** Список версий без тел конфигов (они большие) — для index. */
    public function list(): array
    {
        return PolicyVersion::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PolicyVersion $v) => $this->presentSummary($v))
            ->all();
    }

    public function get(int $id): array
    {
        /** @var PolicyVersion $version */
        $version = PolicyVersion::query()->findOrFail($id);

        return $this->presentSummary($version) + ['config' => $version->config, 'notes' => $version->notes];
    }

    /** Отладочный резолв для админки: какая версия действует на дату. */
    public function resolveSummary(\DateTimeInterface $at): array
    {
        $policy = $this->forDate($at);

        return [
            'at' => CarbonImmutable::parse($at)->toIso8601String(),
            'policy_version_id' => $policy->versionId(),
            'code' => $policy->versionCode(),
            'config_hash' => $policy->configHash(),
            'valid_from' => $policy->validFrom()?->toIso8601String(),
            'valid_to' => $policy->validTo()?->toIso8601String(),
        ];
    }

    private function presentSummary(PolicyVersion $v): array
    {
        return [
            'id' => $v->id,
            'code' => $v->code,
            'status' => $v->status,
            'schema_version' => $v->schema_version,
            'config_hash' => $v->config_hash,
            'valid_from' => $v->valid_from?->toIso8601String(),
            'valid_to' => $v->valid_to?->toIso8601String(),
            'activated_at' => $v->activated_at?->toIso8601String(),
            'activated_by' => $v->activated_by,
            'created_by' => $v->created_by,
            'created_at' => $v->created_at?->toIso8601String(),
            'updated_at' => $v->updated_at?->toIso8601String(),
        ];
    }

    /** Компактный вид для аудита (конфиг — только hash, не тело: он большой). */
    private function auditView(PolicyVersion $v): array
    {
        return [
            'code' => $v->code,
            'status' => $v->status,
            'schema_version' => $v->schema_version,
            'config_hash' => $v->config_hash,
            'valid_from' => $v->valid_from?->toIso8601String(),
            'valid_to' => $v->valid_to?->toIso8601String(),
            'notes' => $v->notes,
        ];
    }
}
