# mh-calc-bot — IziGo Telegram bot (grammY)

Входящий бот: онбординг, deep-link приглашения, запуск Mini App. **Исходящие**
уведомления (реферал/бонус/ранг) шлёт backend напрямую через Telegram Bot API.

## Команды
- `/start [ref]` — приветствие + кнопка «Открыть IziGo» (Mini App). `ref` — payload deep-link.
- `/app` — открыть кабинет (Mini App).
- `/help` — помощь.

## Конфигурация (env — только несекретное)
- `KEY_VAULT_NAME` (по умолчанию `kv-bronxtc-dev`)
- `BOT_TOKEN_SECRET_NAME` (по умолчанию `izigo--beta--TELEGRAM-BOT-TOKEN`)
- `MINI_APP_URL` — https-URL Mini App (для WebApp-кнопки)
- `SENTRY_DSN_SECRET_NAME` (по умолчанию `izigo--beta--SENTRY-DSN`), `APP_ENV`, `GIT_SHA` — для Sentry (опц.)

Sentry (self-host sentry.adarasoft.com): DSN тянется из Key Vault best-effort; без DSN
просто не включается. Создать Sentry-проект `izigo` и положить DSN в KV — на деплое.

**Секрет (токен бота) — только из Azure Key Vault** через `DefaultAzureCredential`
(managed identity в проде; `az login` локально). В env/коде токена нет.

## Запуск
```bash
npm install
MINI_APP_URL=https://<frontend>/miniapp npm start   # требует доступ к Key Vault
npm test                                            # node --test (без сети/секретов)
```

## Деплой (ACA) — по approval
Отдельная revision/контейнер на Azure Container Apps, **без ingress** (long-polling),
single-replica, managed identity с доступом к `kv-bronxtc-dev`. Добавить RG в
`server-watchdog`. Шаги деплоя — отдельной задачей с подтверждением.

## Приглашения / связка с Mini App
Инвайт-ссылка: `t.me/Izigopro_mlm_bot/<app>?startapp=<ref_code>` — открывает Mini App
с `start_param=ref_code`, backend ставит нового партнёра под спонсора.
