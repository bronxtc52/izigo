<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\MemberCopartner;

/**
 * C6 (Block C): READ-ONLY просмотр со-партнёров/наследников участника в веб-админке
 * (web.admin + calculator.role:owner,finance,support).
 *
 * Контракт Gate-A п.16: админка ТОЛЬКО просматривает — здесь НЕТ write-методов
 * (создание/правка/удаление доступны лишь самому партнёру через cabinet). Данные
 * справочные, на деньги/дерево/движок не влияют.
 *
 * @group Copartners
 */
class CopartnerAdminController
{
    /** Список со-партнёров/наследников участника {id} — только чтение. */
    public function index(Request $request, int $id): JsonResponse
    {
        $items = MemberCopartner::query()
            ->where('member_id', $id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (MemberCopartner $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'full_name' => $c->full_name,
                'phone' => $c->phone,
                'share_percent' => $c->share_percent,
                'note' => $c->note,
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['status' => 'success', 'data' => $items]);
    }
}
