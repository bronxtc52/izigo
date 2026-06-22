<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\Log;
use Modules\Calculator\Models\AdminAuditLog;
use Throwable;

/**
 * Аудит-лог админ-действий: запись (кто/что/над чем + before→after) и чтение для
 * экрана «Аудит». Для деньги-критичных мутаций (план, роли) вызывается ВНУТРИ той же
 * транзакции, что и сама мутация — атомарно (либо и мутация, и аудит, либо ничего).
 */
class AuditLogService
{
    public function record(
        ?int $actorMemberId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
        AdminAuditLog::query()->create([
            'actor_member_id' => $actorMemberId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }

    /**
     * Best-effort запись аудита для операций, уже закоммиченных сервисом (выплаты/продукты/
     * KYC/заказы): падение лога не должно ронять завершённую операцию (напр. on-chain выплату
     * нельзя «откатить» из-за ошибки лога). Ошибку глотаем и пишем в Log.
     */
    public function recordSafe(
        ?int $actorMemberId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
        try {
            $this->record($actorMemberId, $action, $entityType, $entityId, $before, $after);
        } catch (Throwable $e) {
            Log::warning('audit recordSafe failed', ['action' => $action, 'entity_id' => $entityId, 'error' => $e->getMessage()]);
        }
    }

    /** Лента аудита (новые сверху) с опц. фильтрами по action/entity_type. */
    public function list(array $filters = []): array
    {
        $query = AdminAuditLog::query()->with('actor:id,name,telegram_username')->orderByDesc('id');

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return [
            'data' => collect($page->items())->map(fn (AdminAuditLog $r) => [
                'id' => $r->id,
                'actor_member_id' => $r->actor_member_id,
                'actor_name' => $r->actor?->name,
                'action' => $r->action,
                'entity_type' => $r->entity_type,
                'entity_id' => $r->entity_id,
                'before' => $r->before,
                'after' => $r->after,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
        ];
    }
}
