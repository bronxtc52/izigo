<?php

return [
    'name' => 'Calculator',

    // Telegram-бот: значение НИКОГДА не хардкодим. В проде на ACA env-переменная
    // TELEGRAM_BOT_TOKEN инжектится из Key Vault через secret keyvaultref
    // (izigo--beta--TELEGRAM-BOT-TOKEN) — источник правды KV, не plain env.
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    // Макс. возраст initData (сек) против replay. Дефолт 1ч (G1): старое окно 24ч давало
    // сутки на переигрывание утёкшего initData к денежному кабинету. Оверрайд —
    // env TELEGRAM_INITDATA_MAX_AGE (в проде инжектится, при необходимости повышается).
    'telegram_initdata_max_age' => (int) env('TELEGRAM_INITDATA_MAX_AGE', 3600),
    // Макс. возраст auth_date Telegram Login Widget (сек) — вход в веб-админку. Дефолт 1ч (G1),
    // тем же аргументом: сузить replay-окно подписанных полей виджета. Оверрайд — env.
    'telegram_login_max_age' => (int) env('TELEGRAM_LOGIN_MAX_AGE', 3600),
    // TTL Sanctum-токена веб-админки (минуты). 0 = бессрочный. Дефолт 12ч — ограничиваем
    // время жизни bearer к денежной панели (выплаты/план). Источник прав — RBAC, не abilities.
    'web_admin_token_ttl_minutes' => (int) env('WEB_ADMIN_TOKEN_TTL_MINUTES', 720),
    // Бутстрап владельцев: список telegram_id через запятую. Источник — Key Vault
    // (izigo--beta--OWNER-TELEGRAM-IDS), инжектится env OWNER_TELEGRAM_IDS. Не хардкодим.
    'owner_telegram_ids' => env('OWNER_TELEGRAM_IDS', ''),
    // Исходящие уведомления в Telegram (opt-in; в тестах выключено по умолчанию).
    'telegram_notify_enabled' => (bool) env('TELEGRAM_NOTIFY_ENABLED', false),

    // Мок-активация пакета БЕЗ оплаты (POST /cabinet/activate-package). Это тест-хелпер:
    // с Фазы 3 activate() пишет реальные выводимые бонусы в ledger аплайну, поэтому в проде
    // бесплатная активация = «печать денег из воздуха» (аудит B-1). Deny-by-default: флаг
    // false → роут отвечает 404 (эффективно вне прод-API). Боевой путь активации — только
    // через оплаченный заказ (OrderService → activate). Включается ТОЛЬКО в тест-окружении
    // (phpunit.xml: CALCULATOR_ALLOW_MOCK_ACTIVATION=true), где нужен как фикстура.
    'allow_mock_activation' => (bool) env('CALCULATOR_ALLOW_MOCK_ACTIVATION', false),

    // Лид-окно (дни): сколько лид закреплён за спонсором до первой покупки. Спонсора
    // можно менять, пока окно не истекло; первая оплата фиксирует спонсора навсегда.
    // По истечении лид открепляется (leads:expire) и может привязаться заново.
    'lead_window_days' => (int) env('LEAD_WINDOW_DAYS', 7),

    // Реф-ссылка — Telegram deep-link на Mini App (платформа Telegram-only, не веб).
    // username бота (без @) и short-name Mini App (BotFather) — ПУБЛИЧНЫЕ, не секреты.
    // Итог: https://t.me/<username>/<short>?startapp=<ref_code> (доставляет start_param);
    // пустой short → https://t.me/<username>?startapp=<ref_code> (главная Mini App бота).
    'telegram_bot_username' => env('TELEGRAM_BOT_USERNAME', 'Izigopro_mlm_bot'),
    'telegram_miniapp_short_name' => env('TELEGRAM_MINIAPP_SHORT_NAME', 'app'),

    // ── Фаза 4 (commerce/платежи) ────────────────────────────────────────────
    // Учётная валюта commerce (модель A — стейблкоин USDT в сети TON).
    'commerce_currency' => env('COMMERCE_CURRENCY', 'USDT'),

    // Приём оплаты. Драйвер: ton_pay (боевой, non-custodial) | ton_pay_fake | fake (тесты/dev).
    // Wallet Pay ОТКЛЮЧЁН полностью (драйвер/класс удалены, wallet_pay → fail-closed throw).
    // Секреты — из Key Vault, инжектятся env, не хардкод.
    'payment_gateway' => env('PAYMENT_GATEWAY', 'ton_pay'),
    // TTL «висящих» pending-платежей (минуты). commerce:tonpay-poll метит их expired ПОСЛЕ
    // финального опроса, чтобы не копились бессрочно. Держим щедрым: приём non-custodial —
    // поздняя оплата по тому же memo подхватывается poll'ом ТОЛЬКО пока платёж pending,
    // поэтому ранняя экспирация = риск «осиротевших» средств на merchant-кошельке.
    // Восстановление: заказ остаётся pending_payment, партнёр создаёт новый платёж. 0 = не истекать.
    'payment_pending_ttl_minutes' => (int) env('PAYMENT_PENDING_TTL_MINUTES', 1440),
    // t2 (P2-tails): порог подряд-ошибок опроса (payments.poll_error_streak) для эскалации —
    // ОДНО Sentry-событие на страйк + маркер «проблемный опрос» в админке. Poll идёт
    // everyMinute → дефолт 10 ≈ 10 минут непрерывных ошибок. 0 = эскалация выключена.
    // Cap = ЭСКАЛАЦИЯ, НЕ авто-экспирация: авто-expire по N ошибок запрещён — деньги могли
    // прийти, опрос лишь не смог это проверить; экспирация закрыла бы подхват поздней
    // оплаты по memo (признанная граница P1/B4, payments.status не расширяется).
    'payment_poll_error_threshold' => (int) env('PAYMENT_POLL_ERROR_THRESHOLD', 10),
    // TON Pay (приём): наш merchant-адрес получателя (izigo--<env>--TON-MERCHANT-ADDRESS) и
    // ключ к TON API для опроса сети (izigo--<env>--TON-API-KEY). Приватный ключ приёма НЕ нужен.
    'ton_merchant_address' => env('TON_MERCHANT_ADDRESS', ''),
    'ton_api_key' => env('TON_API_KEY', ''),
    // База toncenter v3 для приёма (структурированные jetton-переводы). Отдельно от payout-базы.
    'ton_api_v3_base_url' => env('TON_API_V3_BASE_URL', 'https://toncenter.com/api/v3'),
    // Мастер-контракт USDT-джеттона (TON), decimals=6. ПУБЛИЧНЫЙ параметр (не секрет).
    'ton_usdt_jetton_master' => env('TON_USDT_JETTON_MASTER', ''),
    // Пагинация опроса приёма (/jetton/transfers). Окно сканируется ДО короткой страницы;
    // ton_poll_max_pages — лишь МЯГКИЙ предел от «убегания» на аномальном всплеске (страховка),
    // при его достижении без совпадения — Log::warning + Sentry (не молчаливый pending).
    'ton_poll_page_size' => (int) env('TON_POLL_PAGE_SIZE', 100),
    'ton_poll_max_pages' => (int) env('TON_POLL_MAX_PAGES', 200),
    // Секрет подписи входящего платёжного webhook (generic). Wallet Pay отключён, но
    // ключ переиспользуется FakeGateway в тестах/dev для проверки подписи тела.
    'walletpay_webhook_secret' => env('WALLETPAY_WEBHOOK_SECRET', ''),

    // On-chain выплаты USDT (TON). Драйвер: ton_usdt (боевой) | fake. Ключ hot-wallet — из Key Vault
    // (izigo--<env>--TON-PAYOUT-WALLET-KEY) — КРИТИЧНЫЙ секрет, никогда в коде/plain env.
    'payout_gateway' => env('PAYOUT_GATEWAY', 'ton_usdt'),
    'ton_payout_wallet_key' => env('TON_PAYOUT_WALLET_KEY', ''),
    'ton_payout_wallet_address' => env('TON_PAYOUT_WALLET_ADDRESS', ''),
    'ton_api_base_url' => env('TON_API_BASE_URL', 'https://toncenter.com/api/v2'),

    // KYC-intake через Telegram Passport. Приватный ключ расшифровки — из Key Vault
    // (izigo--<env>--PASSPORT-PRIVATE-KEY). Порог суммы (USDT-центы), ВЫШЕ которого нужен
    // одобренный KYC для вывода. null (env не задан) = гейт выключен (поведение Фазы 3).
    'kyc_threshold_cents' => env('KYC_THRESHOLD_CENTS') !== null ? (int) env('KYC_THRESHOLD_CENTS') : null,
    'passport_private_key' => env('PASSPORT_PRIVATE_KEY', ''),

    // ── AI-ассистент ─────────────────────────────────────────────────────────
    // API-ключ Anthropic — ТОЛЬКО из Key Vault (izigo--prod--ANTHROPIC-API-KEY).
    // В рантайме тянем через managed identity; в local-разработке — через .env (placeholder).
    'anthropic_api_key' => env('ANTHROPIC_API_KEY', ''),
    // Модель вынесена в конфиг: смена без правки кода.
    'anthropic_model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    // Rate limit: запросов в минуту на одного партнёра.
    'assistant_rate_per_minute' => (int) env('ASSISTANT_RATE_PER_MINUTE', 10),
];
