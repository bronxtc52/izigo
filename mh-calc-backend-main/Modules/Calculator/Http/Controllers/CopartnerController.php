<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberCopartner;

/**
 * C6 (Block C): со-партнёры / наследники в профиле партнёра (cabinet, telegram.auth).
 *
 * ЧИСТО СПРАВОЧНЫЕ данные — НЕ влияют на деньги/дерево/движок/авторизацию. Партнёр
 * ведёт НЕСКОЛЬКО записей и трогает ТОЛЬКО свои (scope по member из request); попытка
 * тронуть чужую запись → 404 (не раскрываем существование). Доля share_percent —
 * справочная, сумма долей НЕ валидируется (контракт Gate-A п.15).
 *
 * @group Copartners
 */
class CopartnerController
{
    /** Список СВОИХ со-партнёров/наследников (новые сверху). */
    public function index(Request $request): JsonResponse
    {
        $items = MemberCopartner::query()
            ->where('member_id', $this->member($request)->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (MemberCopartner $c) => $this->present($c))
            ->all();

        return response()->json(['status' => 'success', 'data' => $items]);
    }

    /** Создать СВОЮ запись. Несколько записей разрешено, сумма долей не проверяется. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $data['member_id'] = $this->member($request)->id;

        $c = MemberCopartner::create($data);

        return response()->json(['status' => 'success', 'data' => $this->present($c)], 201);
    }

    /** Обновить ТОЛЬКО свою запись (чужая → 404). */
    public function update(Request $request, int $id): JsonResponse
    {
        $c = $this->ownRecordOrFail($request, $id);
        $c->fill($this->validatePayload($request));
        $c->save();

        return response()->json(['status' => 'success', 'data' => $this->present($c)]);
    }

    /** Удалить ТОЛЬКО свою запись (чужая → 404). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $c = $this->ownRecordOrFail($request, $id);
        $c->delete();

        return response()->json(['status' => 'success', 'data' => ['id' => $id, 'deleted' => true]]);
    }

    /**
     * Запись текущего участника или 404. Гарантирует, что партнёр трогает ТОЛЬКО свои
     * данные — это реальная защита на бэкенде, не только скрытие кнопки в UI.
     */
    private function ownRecordOrFail(Request $request, int $id): MemberCopartner
    {
        return MemberCopartner::query()
            ->where('id', $id)
            ->where('member_id', $this->member($request)->id)
            ->firstOrFail();
    }

    /** Валидация полей. БЕЗ проверки суммы долей (справочные данные). */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'kind' => 'required|string|in:copartner,heir',
            'full_name' => 'required|string|max:160',
            'phone' => 'nullable|string|max:32',
            'share_percent' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|string|max:255',
        ]);
    }

    private function present(MemberCopartner $c): array
    {
        return [
            'id' => $c->id,
            'kind' => $c->kind,
            'full_name' => $c->full_name,
            'phone' => $c->phone,
            'share_percent' => $c->share_percent,
            'note' => $c->note,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('member');
    }
}
