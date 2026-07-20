# IziGo (binar-mlm)

MLM-платформа с бинарным комп-планом: `mh-calc-backend-main/` (Laravel 12 + Postgres/ltree,
модуль `Modules/Calculator`), `mh-calc-frontend-main/` (Next 15 + React 19 App Router с 2026-07-10,
Telegram Mini App «Aurora»; приёмка фронта — `scripts/smoke.sh` + `scripts/browser-smoke.sh`),
`mh-calc-bot/` (grammY, Node 20). Актуальный статус/итерация — `plan.md`; роадмап —
`roadmap.md`; ТЗ и отчёты — `docs/specs/`, `docs/reviews/`.

## Жёсткие рамки

- **Движок расчёта бонусов (`Modules/Calculator` ядро CompensationEngine) не трогаем** — фичи
  только читают его выходы. Валюта USD, авторизация и платежи только Telegram (initData /
  Login Widget + TON Pay), выплаты — вручную by design.
- Любой путь к `ActivationService::activate()` берёт advisory-lock **до** своих ledger-записей
  (см. `AutoshipService::processOne`) — иначе дедлок с конкурентным пересчётом.

## Git / деплой

- **Trunk-based hybrid: push/merge в main = прод-деплой** (ACA). Мелочь/доки — прямой push с
  `[skip ci]`; рисковое — ветка + PR. Мердж в main — только с явного «ок» пользователя.
- CI (`.github/workflows/deploy.yml`): job `test` (Postgres 16, route:cache-smoke,
  `php artisan test`, фронт lint+build) на PR и push; деплой — `needs: test`.
  ⚠️ `paths-ignore: docs/**, **.md` — docs-only PR не запускает `test`.
- Прод: RG `rg-izigo-beta-neu`, фронт `https://izigo.adarasoft.com` (+ `admin.izigo.adarasoft.com`),
  бэкенд `https://ca-izigo-backend.livelycoast-2b4dcf83.northeurope.azurecontainerapps.io`.
  ⚠️ MSI mh-central имеет на этот RG **read**-роли (Reader/Monitoring Reader/Log Analytics Reader,
  без Remediator) с 2026-07-03: `az list/show` работают, но прод-write (restart/деплой) — только из
  авторизованной сессии пользователя (`az login`), MSI откажет; живость всё равно удобно смотреть
  HTTP-смоуком; KV читается.
- **📡 Мониторинг (2026-07-03, блокер B-4):** 12 silent metric-алёртов `al-ca-izigo-*`
  (backend/frontend/bot × no-replicas/restarts(max>3)/cpu/mem) + 2 scheduled-query, `log-izigo`
  daily-cap 2GB; RG в `server-watchdog` (`AZURE_RESOURCE_GROUPS` #9 + `RG_WORKSPACE`) + fleet
  http-чек `/api/health`. Скрипт `ops/alerts-izigo.sh`, runbook `ops/izigo-monitoring-setup.md`.
- **Health-эндпоинты бэка:** `/api/health` (БД + свежесть heartbeat планировщика, 503 при протухании)
  и `/up` (дешёвый liveness). Деплой проверяет healthState именно НОВОЙ ревизии (шаг в deploy.yml),
  не только URL — застрявшая ревизия (PullingImage/Degraded) роняет деплой, а не прячется за старой.
- **💾 Бэкапы Postgres (2026-07-03):** PITR 14 дней + ночной logical-дамп на mh-central
  (cron 03:30 UTC, `ops/backup-izigo-pg.sh` → `~/backups/izigo-pg/`, ротация 30 дней).
  ⚠️ Geo-redundant НЕ включается на живом сервере — потерю региона/удаление сервера покрывают
  только дампы. Восстановление (PITR/дамп) + квартальный drill — `ops/izigo-pg-backup-restore.md`.

## Тесты

- PHP на хосте нет — докер: образ `izigo-php-dev` + контейнер `izigo-test-pg`
  (postgres:16-alpine на 127.0.0.1:5544, db `izigo_test`):
  `docker run --rm --network host -v $PWD/mh-calc-backend-main:/app -e DB_CONNECTION=pgsql \
   -e DB_HOST=127.0.0.1 -e DB_PORT=5544 -e DB_USERNAME=postgres -e DB_PASSWORD=postgres \
   izigo-php-dev php artisan test`
- Тесты требуют реального Postgres (ltree) — sqlite не подходит. Паттерны: трейт
  `SignsTelegramInitData`, `FakeTonPayGateway` (fakePay/failFor), `enableFeatureFlags(...)` —
  роуты Блока C гейтятся `feature.flag:{alias}`, deny-by-default.
- Бот: `cd mh-calc-bot && npm test`; фронт: `npm run lint && npm run build`.

## Секреты / observability

- KV `kv-bronxtc-dev`, namespace `izigo--beta--*` (бот-токен, Sentry DSN бэка/бота,
  `SENTRY-DSN-FRONTEND`). Фронтовый DSN также в gh secret `FRONTEND_SENTRY_DSN` (build-arg).
- Sentry self-hosted: проекты `izigo` (бэк/бот) и `izigo-frontend` (id 52).
- `NEXT_PUBLIC_*` запекаются при сборке (ARG в Dockerfile + build-args в deploy.yml), не рантайм.
- Мониторинг izigo (Azure Monitor silent-алёрты) — `ops/alerts-izigo.sh`; подключение к
  server-watchdog + read-роли MI + ACA health-probe — runbook `ops/izigo-monitoring-setup.md`.
  ⚠️ RG `rg-izigo-beta-neu` в `AZURE_RESOURCE_GROUPS` watchdog и алёрты заводит **человек** из
  авторизованной сессии (MSI mh-central RG не видит).
