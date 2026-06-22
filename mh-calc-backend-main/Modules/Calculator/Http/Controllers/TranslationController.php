<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\Services\I18n\TranslationService;
use RuntimeException;

/**
 * C4 (Block C): редактируемые переводы (i18n-оверрайды). Тонкий контроллер — делегирует
 * TranslationService.
 *  - public read: карта оверрайдов (по locale или все) для фронт-мёржа поверх статики.
 *    Переводы не секретны, а логин-страница админки и Mini App нуждаются в строках ДО auth —
 *    поэтому read публичный (graceful: фронт падает на статику при недоступности эндпоинта).
 *  - admin (web.admin + calculator.role:owner): список + upsert + delete оверрайдов.
 * RBAC задаётся на маршрутах. Запретные зоны (Modules/ConfigIziGo) не трогаем.
 *
 * @group I18n
 */
class TranslationController
{
    public function __construct(
        private readonly TranslationService $service,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * Public: карта оверрайдов для фронта. ?locale=ru → key→value одной локали;
     * без параметра → locale→(key→value) всех локалей (только непустые).
     */
    public function overrides(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request) {
            $locale = $request->query('locale');
            if (is_string($locale) && $locale !== '') {
                return $this->service->overridesForLocale($locale);
            }

            return $this->service->allOverrides();
        });
    }

    /** Admin (owner): полный список оверрайдов с метаданными. Опционально ?locale=. */
    public function index(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request) {
            $locale = $request->query('locale');

            return $this->service->list(is_string($locale) && $locale !== '' ? $locale : null);
        });
    }

    /** Admin (owner): создать/обновить оверрайд (locale,key)→value. Пишется в аудит. */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => 'required|string|max:8',
            'key' => 'required|string|max:255',
            'value' => 'required|string',
        ]);

        return $this->guarded(function () use ($request, $data) {
            $actor = $this->viewer($request);
            $row = $this->service->upsert($data['locale'], $data['key'], $data['value'], $actor->id);
            $this->audit->recordSafe(
                $actor->id,
                'translation_override.upsert',
                'translation_override',
                $row['id'] ?? null,
                null,
                ['locale' => $data['locale'], 'key' => $data['key']],
            );

            return $this->service->list();
        });
    }

    /** Admin (owner): удалить оверрайд (вернуть к статическому дефолту). Пишется в аудит. */
    public function delete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => 'required|string|max:8',
            'key' => 'required|string|max:255',
        ]);

        return $this->guarded(function () use ($request, $data) {
            $actor = $this->viewer($request);
            $this->service->delete($data['locale'], $data['key']);
            $this->audit->recordSafe(
                $actor->id,
                'translation_override.delete',
                'translation_override',
                null,
                ['locale' => $data['locale'], 'key' => $data['key']],
                null,
            );

            return $this->service->list();
        });
    }

    /** Текущий участник-наблюдатель (web.admin резолвит в request('member')). */
    private function viewer(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
