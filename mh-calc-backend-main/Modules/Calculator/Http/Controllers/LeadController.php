<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Services\CabinetService;
use Modules\Calculator\Services\LeadService;
use RuntimeException;

/**
 * Действия лида (ещё не купил пакет): смена спонсора в пределах окна. Авторизация —
 * telegram.auth; лид лежит в request('lead'). Участник (уже купил) сюда не попадает —
 * у него спонсор зафиксирован навсегда.
 *
 * @group Cabinet
 */
class LeadController
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly CabinetService $cabinet,
    ) {
    }

    public function changeSponsor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ref_code' => 'required|string|max:16',
        ]);

        $lead = $request->attributes->get('lead');
        if ($lead === null) {
            // Либо уже участник (спонсор замкнут), либо нет идентичности.
            return response()->json([
                'status' => 'error',
                'message' => $request->attributes->get('member') !== null
                    ? 'Спонсор уже зафиксирован покупкой'
                    : 'Откройте по реферальной ссылке',
            ], 409);
        }

        try {
            $lead = $this->leads->changeSponsor($lead, (string) $validated['ref_code']);

            return response()->json(['status' => 'success', 'data' => $this->cabinet->leadState($lead)]);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
