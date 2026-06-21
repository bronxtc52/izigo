<?php

return [
    'name' => 'Calculator',

    // Telegram-бот: значение НИКОГДА не хардкодим. В проде на ACA env-переменная
    // TELEGRAM_BOT_TOKEN инжектится из Key Vault через secret keyvaultref
    // (izigo--beta--TELEGRAM-BOT-TOKEN) — источник правды KV, не plain env.
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    // Макс. возраст initData (сек) против replay.
    'telegram_initdata_max_age' => (int) env('TELEGRAM_INITDATA_MAX_AGE', 86400),
    // Исходящие уведомления в Telegram (opt-in; в тестах выключено по умолчанию).
    'telegram_notify_enabled' => (bool) env('TELEGRAM_NOTIFY_ENABLED', false),
];
