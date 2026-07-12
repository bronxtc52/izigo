<?php

use Illuminate\Support\Facades\Route;
use Modules\Calculator\Http\Controllers\AdminController;
use Modules\Calculator\Http\Controllers\AdminReportController;
use Modules\Calculator\Http\Controllers\AiAssistantController;
use Modules\Calculator\Http\Controllers\AuthController;
use Modules\Calculator\Http\Controllers\CabinetController;
use Modules\Calculator\Http\Controllers\CalculatorController;
use Modules\Calculator\Http\Controllers\CommerceAdminController;
use Modules\Calculator\Http\Controllers\CommerceController;
use Modules\Calculator\Http\Controllers\LeadController;
use Modules\Calculator\Http\Controllers\PackageController;
use Modules\Calculator\Http\Controllers\RankController;
use Modules\Calculator\Http\Controllers\WebhookController;
use Modules\Calculator\Http\Middleware\StructureEditTokenMiddleware;

// Авторизация платформы — через Telegram. Mini App (партнёр) — middleware telegram.auth
// (заголовок X-Telegram-Init-Data). ВЕБ-админка — Telegram Login Widget → Sanctum-токен
// (middleware web.admin). Классического email/пароля нет.

// Вход в веб-админку: Login Widget отдаёт подписанные поля → Sanctum-токен. Без auth.
Route::post('auth/telegram-login', [AuthController::class, 'telegramLogin'])->name('auth.telegram-login');

Route::group([
    'prefix' => 'calculator',
    'as' => 'calculator.'
], function () {

    // Структура - редактирование
    Route::group([
        'prefix' => 'structure',
        'as' => 'structure.',
        'middleware' => ['calculator.validate.token']
    ], function () {
        Route::get('/last', [CalculatorController::class, 'structureGetLast'])->name('structure-get-last');
        Route::get('/all', [CalculatorController::class, 'structureGetAll'])->name('structure-get-all');
        Route::post('/', [CalculatorController::class, 'structureCreate'])->name('create');

        Route::group([
            'prefix' => 'node',
            'as' => 'node.',
        ], function () {
            Route::post('/set-structure-package', [CalculatorController::class, 'setStructurePackage'])->name('set-structure-package');
            Route::post('/create', [CalculatorController::class, 'addNode'])->name('create');
            Route::put('/update/{node_id}', [CalculatorController::class, 'updateNode'])->name('update')
                ->where(['node_id' => '[0-9]+']);
            Route::delete('/delete/{node_id}', [CalculatorController::class, 'deleteNode'])->name('delete')
                ->where(['node_id' => '[0-9]+']);
        });

        Route::delete('/{structure}', [CalculatorController::class, 'structureClear'])->name('clear')
            ->where(['structure' => '[A-Za-z0-9]+'])
            ->middleware(StructureEditTokenMiddleware::class . ':structure');

        Route::get('/{structure_token}/details/{node_id}', [CalculatorController::class, 'getNodeDetails'])
            ->name('node-details')
            ->where(['structure_token' => '[A-Za-z0-9]+', 'node_id' => '[0-9]+']);

        Route::get('/{structure_token}', [CalculatorController::class, 'getStructure'])->name('index')
            ->where(['structure_token' => '[A-Za-z0-9]+']);
    });

// Пакеты
    Route::get('/packages', [PackageController::class, 'index'])->name('packages');

// Ранги
    Route::get('/ranks', [RankController::class, 'index'])->name('ranks');

});

// Кабинет партнёра — авторизация по Telegram initData; участник в request('member').
Route::group([
    'prefix' => 'cabinet',
    'as' => 'cabinet.',
    'middleware' => ['telegram.auth'],
], function () {
    Route::get('/me', [CabinetController::class, 'me'])->name('me');
    // Персист выбранного языка интерфейса партнёра (members.language).
    Route::patch('/profile/language', [CabinetController::class, 'updateLanguage'])->name('profile-language');
    Route::get('/dashboard', [CabinetController::class, 'dashboard'])->name('dashboard');
    Route::get('/rank-progress', [CabinetController::class, 'rankProgress'])->name('rank-progress');
    Route::get('/team-tree', [CabinetController::class, 'teamTree'])->name('team-tree');
    // Личные рефералы (sponsor_id, любая глубина) — отдельно от бинар-дерева (team-tree).
    Route::get('/personal-referrals', [CabinetController::class, 'personalReferrals'])->name('personal-referrals');
    // Действие лида (ещё не купил): сменить спонсора в пределах окна.
    Route::post('/lead/change-sponsor', [LeadController::class, 'changeSponsor'])->name('lead-change-sponsor');
    // Мок-активация БЕЗ оплаты — тест-фикстура, в проде выключена (deny-by-default): с Фазы 3
    // activate() пишет реальные выводимые бонусы в ledger аплайну, поэтому бесплатная активация =
    // «печать денег из воздуха» (аудит B-1). Гейт config-флагом calculator.allow_mock_activation
    // (флаг OFF → 404). Боевая активация — только через оплаченный заказ (OrderService::activate).
    Route::post('/activate-package', [CabinetController::class, 'activate'])
        ->middleware('mock.activation')->name('activate-package');
    // Кошелёк (Фаза 3): баланс из кэша + лента движений доступного баланса.
    Route::get('/wallet', [CabinetController::class, 'wallet'])->name('wallet');
    Route::get('/wallet/transactions', [CabinetController::class, 'walletTransactions'])->name('wallet-transactions');
    Route::get('/wallet/statement', [CabinetController::class, 'walletStatement'])->name('wallet-statement');

    // B3: пользовательское соглашение (онбординг). Auth остаётся Telegram-only.
    Route::get('/agreement', [CabinetController::class, 'agreement'])->name('agreement');
    Route::post('/agreement/accept', [CabinetController::class, 'acceptAgreement'])->name('agreement-accept');
    // Заявки на вывод партнёра (Фаза 3): создание с холдом + список своих.
    Route::get('/withdrawals', [CabinetController::class, 'withdrawals'])->name('withdrawals');
    Route::post('/withdrawals', [CabinetController::class, 'createWithdrawal'])->name('withdrawals-create');

    // Commerce (Фаза 4, S1): витрина каталога и заказы партнёра.
    Route::get('/catalog', [CommerceController::class, 'catalog'])->name('catalog');
    Route::get('/orders', [CommerceController::class, 'orders'])->name('orders');
    Route::post('/orders', [CommerceController::class, 'createOrder'])->name('orders-create');
    Route::get('/orders/{id}', [CommerceController::class, 'order'])->name('order')
        ->where('id', '[0-9]+');
    // Оплата заказа и пополнение баланса (Фаза 4, S3) — инвойс TON Pay (non-custodial).
    Route::post('/orders/{id}/pay', [CommerceController::class, 'payOrder'])->name('orders-pay')
        ->where('id', '[0-9]+');
    Route::post('/wallet/topup', [CommerceController::class, 'topup'])->name('wallet-topup');
    // TON Pay (S3-TON): немедленная проверка статуса своего платежа (non-custodial poll).
    Route::post('/payments/{id}/check', [CommerceController::class, 'checkPayment'])->name('payment-check')
        ->where('id', '[0-9]+');

    // Autoship-подписки (Фаза 4, S6).
    Route::get('/autoship', [CommerceController::class, 'autoshipList'])->name('autoship');
    Route::post('/autoship', [CommerceController::class, 'autoshipCreate'])->name('autoship-create');
    Route::patch('/autoship/{id}', [CommerceController::class, 'autoshipUpdate'])->name('autoship-update')
        ->where('id', '[0-9]+');

    // KYC-intake (Фаза 4, S8): подача Telegram Passport + статус.
    Route::get('/kyc', [CommerceController::class, 'kycStatus'])->name('kyc');
    Route::post('/kyc/passport', [CommerceController::class, 'kycSubmit'])->name('kyc-submit');

    // AI-ассистент партнёра: вопросы по KB, за feature flag ai_assistant.
    Route::post('/assistant/ask', [AiAssistantController::class, 'ask'])->name('assistant-ask');
});

// Публичные webhook'и платёжных шлюзов (Фаза 4, S3). Без telegram.auth — проверка
// подписи внутри драйвера шлюза.
Route::post('webhooks/wallet-pay', [WebhookController::class, 'walletPay'])->name('webhooks.wallet-pay');

// Админ-портал (веб-админка admin.izigo.adarasoft.com) — Sanctum-токен (web.admin) +
// RBAC-гейты. owner проходит всегда (в RoleMiddleware). В Mini App админка больше не доступна.
Route::group([
    'prefix' => 'admin',
    'as' => 'admin.',
    'middleware' => ['web.admin'],
], function () {
    // Выход/отзыв токена веб-админки (G1): удаляет текущий Sanctum-токен (?all=1 — все).
    // Доступен любой аутентифицированной роли (RBAC-гейт не нужен — отзываешь только свой токен).
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('/members', [AdminController::class, 'members'])
        ->middleware('calculator.role:owner,finance,support,leader')->name('members');
    Route::get('/members/{id}', [AdminController::class, 'member'])
        ->middleware('calculator.role:owner,finance,support,leader')->where('id', '[0-9]+')->name('member');
    Route::post('/members/{id}/role', [AdminController::class, 'assignRole'])
        ->middleware('calculator.role:owner')->where('id', '[0-9]+')->name('assign-role');
    Route::delete('/members/{id}/role', [AdminController::class, 'revokeRole'])
        ->middleware('calculator.role:owner')->where('id', '[0-9]+')->name('revoke-role');
    Route::get('/plan-settings', [AdminController::class, 'planSettings'])
        ->middleware('calculator.role:owner,finance,support')->name('plan-settings');
    Route::put('/plan-settings', [AdminController::class, 'updatePlanSettings'])
        ->middleware('calculator.role:owner')->name('update-plan-settings');

    // Маркетинг-план (полный документ: проценты/ранги/пакеты боевого ядра).
    Route::get('/plan', [AdminController::class, 'plan'])
        ->middleware('calculator.role:owner,finance,support')->name('plan');
    Route::put('/plan', [AdminController::class, 'updatePlan'])
        ->middleware('calculator.role:owner')->name('plan-update');

    // Аудит-лог админ-действий (план/роли/выплаты). Только владелец.
    Route::get('/audit-log', [AdminController::class, 'auditLog'])
        ->middleware('calculator.role:owner')->name('audit-log');

    // Дашборд (KPI) + Финансы (ledger, кошелёк партнёра) + Операции (платежи, autoship).
    Route::get('/dashboard', [AdminReportController::class, 'dashboard'])
        ->middleware('calculator.role:owner,finance,support')->name('dashboard');
    Route::get('/ledger', [AdminReportController::class, 'ledger'])
        ->middleware('calculator.role:owner,finance')->name('ledger');
    Route::get('/members/{id}/wallet', [AdminReportController::class, 'memberWallet'])
        ->middleware('calculator.role:owner,finance,support')->where('id', '[0-9]+')->name('member-wallet');
    Route::get('/payments', [AdminReportController::class, 'payments'])
        ->middleware('calculator.role:owner,finance,support')->name('payments');
    // Принудительный ре-опрос платежа (B4): pending/expired → проверка сети → confirm.
    Route::post('/payments/{id}/recheck', [CommerceAdminController::class, 'recheckPayment'])
        ->middleware('calculator.role:owner,finance')->where('id', '[0-9]+')->name('payments-recheck');
    Route::get('/autoship', [AdminReportController::class, 'autoship'])
        ->middleware('calculator.role:owner,support')->name('admin-autoship');

    // Отчёты/аналитика (A1 роадмапа): read-only сводки поверх выходов движка. USD-only,
    // математику бонусов не трогаем. Балансы/расход — финансовые (owner,finance).
    Route::get('/reports/balances', [AdminReportController::class, 'reportBalances'])
        ->middleware('calculator.role:owner,finance')->name('reports-balances');
    Route::get('/reports/users', [AdminReportController::class, 'reportUsers'])
        ->middleware('calculator.role:owner,finance,support')->name('reports-users');
    Route::get('/reports/sales', [AdminReportController::class, 'reportSales'])
        ->middleware('calculator.role:owner,finance,support')->name('reports-sales');
    Route::get('/reports/bonus-expense', [AdminReportController::class, 'reportBonusExpense'])
        ->middleware('calculator.role:owner,finance')->name('reports-bonus-expense');

    // Генеалогия (B1): read-only бинарное дерево живой сети. Структуру не меняем.
    Route::get('/genealogy', [AdminReportController::class, 'genealogy'])
        ->middleware('calculator.role:owner,finance,support')->name('genealogy');

    // B3: текст пользовательского соглашения — просмотр (owner,support), правка (owner).
    Route::get('/agreement', [AdminController::class, 'agreement'])
        ->middleware('calculator.role:owner,support')->name('agreement');
    Route::put('/agreement', [AdminController::class, 'updateAgreement'])
        ->middleware('calculator.role:owner')->name('agreement-update');

    // Ручной перенос участника (B2): меняет ВХОД движка → owner-only, обязателен dry-run preview.
    Route::post('/genealogy/preview-move', [AdminController::class, 'previewMove'])
        ->middleware('calculator.role:owner')->name('genealogy-preview-move');
    Route::post('/genealogy/move', [AdminController::class, 'move'])
        ->middleware('calculator.role:owner')->name('genealogy-move');

    // Заявки на вывод (Фаза 3): очередь + статус-машина. Только финансист/владелец.
    Route::get('/withdrawals', [AdminController::class, 'withdrawals'])
        ->middleware('calculator.role:owner,finance')->name('withdrawals');
    Route::post('/withdrawals/{id}/approve', [AdminController::class, 'approveWithdrawal'])
        ->middleware('calculator.role:owner,finance')->where('id', '[0-9]+')->name('withdrawals-approve');
    Route::post('/withdrawals/{id}/reject', [AdminController::class, 'rejectWithdrawal'])
        ->middleware('calculator.role:owner,finance')->where('id', '[0-9]+')->name('withdrawals-reject');
    Route::post('/withdrawals/{id}/mark-paid', [AdminController::class, 'markPaidWithdrawal'])
        ->middleware('calculator.role:owner,finance')->where('id', '[0-9]+')->name('withdrawals-mark-paid');
    // Фаза 4 (S7): выплата on-chain в USDT (approved → paid + tx_hash).
    Route::post('/withdrawals/{id}/send', [AdminController::class, 'sendWithdrawal'])
        ->middleware('calculator.role:owner,finance')->where('id', '[0-9]+')->name('withdrawals-send');
    Route::post('/withdrawals/{id}/cancel', [AdminController::class, 'cancelWithdrawal'])
        ->middleware('calculator.role:owner,finance')->where('id', '[0-9]+')->name('withdrawals-cancel');

    // Каталог (Фаза 4, S1): управление товарами. owner/support.
    Route::get('/products', [CommerceAdminController::class, 'products'])
        ->middleware('calculator.role:owner,support')->name('products');
    Route::post('/products', [CommerceAdminController::class, 'createProduct'])
        ->middleware('calculator.role:owner,support')->name('products-create');
    Route::put('/products/{id}', [CommerceAdminController::class, 'updateProduct'])
        ->middleware('calculator.role:owner,support')->where('id', '[0-9]+')->name('products-update');
    Route::delete('/products/{id}', [CommerceAdminController::class, 'deleteProduct'])
        ->middleware('calculator.role:owner,support')->where('id', '[0-9]+')->name('products-delete');

    // Заказы (Фаза 4, S5): список + смена статуса исполнения. owner/support.
    Route::get('/orders', [CommerceAdminController::class, 'orders'])
        ->middleware('calculator.role:owner,support')->name('orders');
    Route::patch('/orders/{id}/status', [CommerceAdminController::class, 'updateOrderStatus'])
        ->middleware('calculator.role:owner,support')->where('id', '[0-9]+')->name('orders-status');

    // KYC-очередь и ревью (Фаза 4, S8). owner/finance.
    Route::get('/kyc', [CommerceAdminController::class, 'kyc'])
        ->middleware('calculator.role:owner,finance')->name('kyc');
    Route::patch('/kyc/{id}', [CommerceAdminController::class, 'reviewKyc'])
        ->middleware('calculator.role:owner,finance')->where('id', '[0-9]+')->name('kyc-review');
});

// Block C — разводка роутов по фичам (см. docs/block-c-migration-ledger.md,
// docs/specs/2026-06-22-block-c-gate-a.md). Каждый стаб определяет свои роуты в
// собственном файле в том же глобальном контексте (фасад Route), чтобы 7 фич
// кодились параллельно без конфликтов в этом файле. Пока стабы пустые — новых
// роутов не добавляют.
require __DIR__ . '/api/notifications.php';   // C1
require __DIR__ . '/api/helpdesk.php';        // C2
require __DIR__ . '/api/feature_flags.php';   // C3
require __DIR__ . '/api/i18n.php';            // C4
require __DIR__ . '/api/exports.php';         // C5
require __DIR__ . '/api/copartners.php';      // C6
require __DIR__ . '/api/monitoring.php';      // C7

// >>> V2 routes (mh-full-plan) — каждая V2-фича добавляет СВОЙ файл в Routes/api/
// и одну require-строку в этот блок (см. docs/mh-full-plan-migration-ledger.md).
require __DIR__ . '/api/v2_policy.php';       // T01/T13
require __DIR__ . '/api/v2_accounts.php';     // T02
require __DIR__ . '/api/v2_periods.php';      // T04
require __DIR__ . '/api/v2_statuses.php';     // T05
require __DIR__ . '/api/v2_volumes.php';      // T03
require __DIR__ . '/api/v2_structure_bonus.php'; // T06
require __DIR__ . '/api/v2_referral.php';     // T07
require __DIR__ . '/api/v2_global_bonus.php'; // T09
require __DIR__ . '/api/v2_awards.php';       // T10
require __DIR__ . '/api/v2_pool.php';         // T11
require __DIR__ . '/api/v2_leadership.php';   // T08
require __DIR__ . '/api/v2_refunds.php';      // T12
require __DIR__ . '/api/miniapp_v2.php';      // T14 (Mini App read-слой плана)
// <<< V2 routes





