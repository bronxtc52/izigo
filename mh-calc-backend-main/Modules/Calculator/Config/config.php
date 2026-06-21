<?php

return [
    'name' => 'Calculator',

    // Telegram-бот: токен берётся из окружения (в проде — из Azure Key Vault
    // izigo--beta--TELEGRAM-BOT-TOKEN). НИКОГДА не хардкодить значение здесь.
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    // Макс. возраст initData (сек) против replay.
    'telegram_initdata_max_age' => (int) env('TELEGRAM_INITDATA_MAX_AGE', 86400),
];
