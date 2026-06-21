<?php

return [
    'name' => 'Calculator',

    // Telegram-бот: значение НИКОГДА не хардкодим. В проде на ACA env-переменная
    // TELEGRAM_BOT_TOKEN инжектится из Key Vault через secret keyvaultref
    // (izigo--beta--TELEGRAM-BOT-TOKEN) — источник правды KV, не plain env.
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    // Макс. возраст initData (сек) против replay.
    'telegram_initdata_max_age' => (int) env('TELEGRAM_INITDATA_MAX_AGE', 86400),
    // Бутстрап владельцев: список telegram_id через запятую. Источник — Key Vault
    // (izigo--beta--OWNER-TELEGRAM-IDS), инжектится env OWNER_TELEGRAM_IDS. Не хардкодим.
    'owner_telegram_ids' => env('OWNER_TELEGRAM_IDS', ''),
    // Исходящие уведомления в Telegram (opt-in; в тестах выключено по умолчанию).
    'telegram_notify_enabled' => (bool) env('TELEGRAM_NOTIFY_ENABLED', false),

    // ── Фаза 4 (commerce/платежи) ────────────────────────────────────────────
    // Учётная валюта commerce (модель A — стейблкоин USDT в сети TON).
    'commerce_currency' => env('COMMERCE_CURRENCY', 'USDT'),

    // Приём оплаты. Драйвер: ton_pay (боевой, non-custodial) | wallet_pay (fallback) |
    // ton_pay_fake | fake (тесты/dev). Секреты — из Key Vault, инжектятся env, не хардкод.
    'payment_gateway' => env('PAYMENT_GATEWAY', 'ton_pay'),
    // TON Pay (приём): наш merchant-адрес получателя (izigo--<env>--TON-MERCHANT-ADDRESS) и
    // ключ к TON API для опроса сети (izigo--<env>--TON-API-KEY). Приватный ключ приёма НЕ нужен.
    'ton_merchant_address' => env('TON_MERCHANT_ADDRESS', ''),
    'ton_api_key' => env('TON_API_KEY', ''),
    // База toncenter v3 для приёма (структурированные jetton-переводы). Отдельно от payout-базы.
    'ton_api_v3_base_url' => env('TON_API_V3_BASE_URL', 'https://toncenter.com/api/v3'),
    // Мастер-контракт USDT-джеттона (TON), decimals=6. ПУБЛИЧНЫЙ параметр (не секрет).
    'ton_usdt_jetton_master' => env('TON_USDT_JETTON_MASTER', ''),
    // Wallet Pay — fallback-драйвер (не активен по умолчанию).
    'walletpay_base_url' => env('WALLETPAY_BASE_URL', 'https://pay.wallet.tg'),
    'walletpay_api_key' => env('WALLETPAY_API_KEY', ''),
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
];
