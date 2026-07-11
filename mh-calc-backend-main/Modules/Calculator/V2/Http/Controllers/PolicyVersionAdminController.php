<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Services\PolicyNotActiveException;
use Modules\Calculator\V2\Services\PolicyVersionService;

/**
 * T01: админ-API версий политики V2. Тонкий контроллер — делегирует
 * {@see PolicyVersionService}. RBAC на маршрутах (Routes/api/v2_policy.php):
 * read — calculator.role:owner,finance; mutation — calculator.role:owner;
 * вся группа под web.admin + feature.flag:mh_plan_v2_admin (deny-by-default).
 */
class PolicyVersionAdminController
{
    public function __construct(private readonly PolicyVersionService $service)
    {
    }

    /** Read: список версий (без тел конфигов). */
    public function index(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->list());
    }

    /** Read: версия целиком (конфиг + hash). */
    public function show(int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->service->get($id));
    }

    /** Read (отладка): какая версия действует на дату ?at= (дефолт — сейчас). */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate(['at' => 'nullable|date']);

        return $this->guarded(fn () => $this->service->resolveSummary(
            isset($data['at']) ? CarbonImmutable::parse($data['at']) : now()->toImmutable(),
        ));
    }

    /** Mutation (owner): создать draft. */
    public function storeDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:64',
            'config' => 'required|array',
            'notes' => 'nullable|string|max:2000',
            'schema_version' => 'nullable|integer|min:1|max:32767',
        ]);

        return $this->guarded(fn () => $this->service->createDraft(
            $data['code'],
            $data['config'],
            $this->viewer($request)->id,
            $data['notes'] ?? null,
            $data['schema_version'] ?? 1,
        )->only(['id', 'code', 'status', 'config_hash']));
    }

    /** Mutation (owner): обновить draft (active/retired immutable → 422). */
    public function updateDraft(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'config' => 'required|array',
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->guarded(fn () => $this->service->updateDraft(
            $id,
            $data['config'],
            $this->viewer($request)->id,
            $data['notes'] ?? null,
        )->only(['id', 'code', 'status', 'config_hash']));
    }

    /** Mutation (owner): активировать draft (one-step owner-activate, MF-8). */
    public function activate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'valid_from' => 'nullable|date',
            'allow_retro' => 'nullable|boolean',
        ]);

        return $this->guarded(fn () => $this->service->activate(
            $id,
            $this->viewer($request)->id,
            isset($data['valid_from']) ? CarbonImmutable::parse($data['valid_from']) : null,
            (bool) ($data['allow_retro'] ?? false),
        )->only(['id', 'code', 'status', 'valid_from', 'config_hash']));
    }

    /** Mutation (owner): retire (active → без активной версии, fail-closed). */
    public function retire(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->service->retire($id, $this->viewer($request)->id)
            ->only(['id', 'code', 'status', 'valid_from', 'valid_to']));
    }

    private function viewer(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (PolicyNotActiveException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
