<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\Services\Pii\ExportService;
use RuntimeException;

/**
 * C5 (Block C): экспорт данных участника (JSON/CSV) + PII reveal. Тонкий контроллер.
 *
 * RBAC задаётся на маршрутах (Routes/api/exports.php):
 *  - summary  (карточка с PII в маске)      → owner, finance, support
 *  - export   (csv|json)                     → owner, finance, support (не-owner = принудит. маска)
 *  - reveal   (сырые значения PII)           → owner ТОЛЬКО
 *
 * Бэкенд — последняя линия обороны: reveal и полный (masked=false) экспорт закрыты на уровне
 * маршрута (owner-only для reveal) И повторно в коде (non-owner всегда masked=true). Каждый
 * reveal/export пишется в аудит (admin_audit_log), БЕЗ сырых значений PII в before/after.
 *
 * @group MemberExport
 */
class MemberExportController
{
    public function __construct(
        private readonly ExportService $exports,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Сводка-карточка участника с PII в МАСКЕ (owner,finance,support). Reveal не делает. */
    public function summary(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->exports->collect($id, masked: true));
    }

    /**
     * Reveal сырых PII участника — ТОЛЬКО owner (гейтится на маршруте calculator.role:owner).
     * Пишет pii.reveal в аудит (факт + перечень раскрытых полей, БЕЗ значений).
     */
    public function reveal(Request $request, int $id): JsonResponse
    {
        return $this->guarded(function () use ($request, $id) {
            $pii = $this->exports->revealPii($id);

            $this->audit->recordSafe(
                $this->viewer($request)?->id,
                'pii.reveal',
                'member',
                $id,
                null,
                // Логируем ТОЛЬКО факт + имена полей. Значения PII в лог НЕ кладём.
                ['fields' => array_keys($pii)],
            );

            return $pii;
        });
    }

    /**
     * Экспорт участника в csv|json. owner,finance,support (на маршруте).
     * masked=false (полный) разрешён ТОЛЬКО owner; для остальных маска принудительна.
     * Каждый экспорт пишется member.export в аудит (формат + masked + поля, БЕЗ значений).
     */
    public function export(Request $request, int $id): Response|JsonResponse
    {
        $format = strtolower((string) $request->query('format', 'json'));
        if (!in_array($format, ['json', 'csv'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Неизвестный формат экспорта'], 422);
        }

        $viewer = $this->viewer($request);
        // Полный (немаскированный) экспорт — только owner. Не-owner всегда masked, даже если
        // прислал masked=0: deny-by-default на бэкенде, не только скрытием кнопки.
        $wantFull = $request->boolean('masked') === false
            && $request->has('masked')
            && (string) $request->query('masked') !== '1';
        $isOwner = $viewer !== null && $viewer->isOwner();
        $masked = !($wantFull && $isOwner);

        try {
            $member = Member::query()->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        }

        $this->audit->recordSafe(
            $viewer?->id,
            'member.export',
            'member',
            $id,
            null,
            ['format' => $format, 'masked' => $masked],
        );

        if ($format === 'csv') {
            $csv = $this->exports->toCsv($id, $masked);
            $filename = "member-{$member->id}" . ($masked ? '-masked' : '') . '.csv';

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        return response()->json(['status' => 'success', 'data' => $this->exports->toJson($id, $masked)]);
    }

    /** Текущий участник-наблюдатель (web.admin резолвит в request('member')). */
    private function viewer(Request $request): ?Member
    {
        return $request->attributes->get('member');
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
