<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AgreementService;
use Modules\Calculator\Services\CabinetService;
use Modules\Calculator\Services\WalletService;
use Modules\Calculator\Services\WithdrawalService;
use RuntimeException;

/**
 * Кабинет партнёра (Telegram Mini App): профиль/реф-ссылка, доход, ранги, дерево,
 * активация. Авторизация — middleware telegram.auth; участник лежит в request('member').
 *
 * @group Cabinet
 */
class CabinetController
{
    public function __construct(
        private readonly CabinetService $service,
        private readonly WalletService $wallet,
        private readonly WithdrawalService $withdrawals,
        private readonly AgreementService $agreement,
    ) {
    }

    public function me(Request $request): JsonResponse
    {
        // Идентичность резолвит middleware: участник, лид (ещё не купил) или никто.
        $member = $request->attributes->get('member');
        if ($member !== null) {
            return $this->guarded(fn () => $this->service->profile($member));
        }

        $lead = $request->attributes->get('lead');
        if ($lead !== null) {
            return $this->guarded(fn () => $this->service->leadState($lead));
        }

        // Валидный Telegram-юзер без спонсора — нужна реф-ссылка для входа в воронку.
        return response()->json(['status' => 'success', 'data' => ['need_referral' => true]]);
    }

    /** Личные рефералы (по sponsor_id, любая глубина) — отдельно от бинар-дерева (team-tree). */
    public function personalReferrals(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->personalReferrals($this->member($request)));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->dashboard($this->member($request)));
    }

    public function rankProgress(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->rankProgress($this->member($request)));
    }

    public function teamTree(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->teamTree($this->member($request)));
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|integer|exists:calculator_packages,id',
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        return $this->guarded(fn () => $this->service->activatePackage(
            $this->member($request),
            (int) $validated['package_id'],
            $validated['idempotency_key'] ?? null,
        ));
    }

    public function wallet(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->wallet->balance($this->member($request)));
    }

    public function walletTransactions(Request $request): JsonResponse
    {
        $cursor = $request->query('cursor');

        return $this->guarded(fn () => $this->wallet->transactions(
            $this->member($request),
            $cursor !== null ? (int) $cursor : null,
        ));
    }

    public function walletStatement(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->wallet->statement(
            $this->member($request),
            $request->query('from'),
            $request->query('to'),
        ));
    }

    public function withdrawals(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->withdrawals->listForMember($this->member($request)));
    }

    public function createWithdrawal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|string|max:32',
            'payout_details' => 'required|string|max:1000',
        ]);

        return $this->guarded(fn () => $this->withdrawals->create(
            $this->member($request),
            (string) $validated['amount'],
            (string) $validated['payout_details'],
        ));
    }

    /**
     * B3: статус пользовательского соглашения для участника (версия/текст + принял ли).
     * Текст отдаётся на языке запроса (ru/en) — см. agreementLocale().
     */
    public function agreement(Request $request): JsonResponse
    {
        $locale = $this->agreementLocale($request);

        return $this->guarded(fn () => $this->agreement->statusFor($this->member($request), $locale));
    }

    /** B3: принять текущую версию соглашения (онбординг). Возвращает статус на языке запроса. */
    public function acceptAgreement(Request $request): JsonResponse
    {
        $locale = $this->agreementLocale($request);

        return $this->guarded(fn () => $this->agreement->accept($this->member($request), $locale));
    }

    /**
     * Сменить язык интерфейса партнёра (персист в members.language). Фронт затем шлёт
     * Accept-Language. Поддерживаемые языки — config('translatable') + en; неизвестный → 422.
     */
    public function updateLanguage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'language' => 'required|string|in:ru,en,kk,mn,uz,ky,az',
        ]);

        $member = $this->member($request);
        $member->language = $validated['language'];
        $member->save();

        return response()->json(['status' => 'success', 'data' => ['language' => $member->language]]);
    }

    /**
     * Язык для соглашения (только ru/en). SetLocale знает ru/kk/mn/uz/ky/az, но НЕ en — для
     * en он молча оставляет дефолт app-locale (ru), поэтому en невозможно отличить от ru
     * только по app()->getLocale(). Решение: сначала смотрим первый язык-тег Accept-Language
     * (en→en), и лишь затем app-locale; всё прочее → дефолт ru (сервис нормализует фолбэк).
     */
    private function agreementLocale(Request $request): string
    {
        $accept = strtolower((string) $request->headers->get('Accept-Language', ''));
        $primary = trim(explode(';', trim(explode(',', $accept)[0] ?? ''))[0] ?? '');
        if (str_starts_with($primary, 'en')) {
            return 'en';
        }
        if (str_starts_with($primary, 'ru')) {
            return 'ru';
        }

        // Нет явного ru/en в заголовке — доверяем app-locale, если он ru/en, иначе ru.
        $appLocale = app()->getLocale();

        return in_array($appLocale, ['ru', 'en'], true) ? $appLocale : 'ru';
    }

    /**
     * Текущий участник (telegram.auth). Лид (ещё не купил) участником НЕ является:
     * member-only эндпоинты для него дают доменную ошибку (gateway → 404).
     */
    private function member(Request $request): Member
    {
        $member = $request->attributes->get('member');
        if ($member === null) {
            throw new RuntimeException('Доступно после активации пакета');
        }

        return $member;
    }

    /** Единый формат успеха + аккуратный 404 при доменной ошибке. */
    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
