# План: IziGo — Фаза 0 (фундамент + облако)

ТЗ: `docs/specs/2026-06-20-izigo-platform-v1.md`. Роадмап: `roadmap.md`.
Объём (подтверждён): полная Фаза 0 включая облако. Калькулятор не ломать (:8000/:3001).

## A. Локальный фундамент (сейчас, безопасно)

- [ ] **git**: `git init`, `.gitignore` (vendor/, node_modules/, .env*, .firecrawl/, /storage, bootstrap/cache),
      ветка `chore/phase-0-foundation` (не main). Baseline-коммит обоих подпроектов + docs/plan/roadmap.
      Conventional Commits. Co-Authored-By в сообщении.
- [ ] **PostgreSQL**: запустить `postgresql@16`, создать БД `izigo` + `izigo_test`. Backend `.env`
      → `DB_CONNECTION=pgsql`. `migrate:fresh` + `php artisan test` (проверить, что миграции
      `->change()`/multiple dropColumn проходят на PG — на PG это ОК, в отличие от SQLite).
- [ ] **Деньги decimal**: правило — деньги/объёмы хранить `decimal`, не float. Миграция: pv/bv
      в `calculator_package_volumes` (сейчас `float(20,2)`) → `decimal(20,2)`. Зафиксировать как стандарт
      в ТЗ/доке для будущего биллинга.

## B. Observability — Sentry (облако)

- [ ] Создать проект в self-host Sentry (`sentry.adarasoft.com`, org-slug `sentry`), получить DSN.
- [ ] DSN → Key Vault (`kv-bronxtc-dev`, имя `izigo--<env>--SENTRY-DSN`). Не в код/git.
- [ ] Подключить `sentry-sdk` в backend (Laravel) — init в entrypoint, DSN из env (тянем из KV).
      Frontend (`@sentry/nextjs`) — опц. в этой фазе. Smoke-тест (тестовое событие).
- [ ] Best-effort шаг релиз-тегирования (не ронять деплой).

## C. Azure-инфра + CI/CD + деплой (облако, approval-gated)

- [ ] Resource group `rg-izigo` (теги `project=izigo,env=...,owner=bronxtc52`), регион — как у проектов.
- [ ] ACR / `az acr build` для образов backend + frontend; Postgres Flexible (B1ms) для prod.
- [ ] ACA: backend (с ingress) + frontend; секреты через keyvaultref (managed identity).
- [ ] GitHub-репо + Actions (OIDC federated creds в Azure), workflow build→deploy.
- [ ] Добавить `rg-izigo` в `server-watchdog` (`AZURE_RESOURCE_GROUPS`).
- [ ] Azure Monitor алёрты (CPU/RAM/доступность) для ACA.

## Порядок и зависимости
A (локально) → B (Sentry) → C (Azure). C зависит от git-репо (для CI) и образов.
Параметры инфры (имя RG/региона, GitHub-репо/орг, домен) — подобрать по конвенциям CLAUDE.md,
спорные — спросить точечно при достижении шага.

## Приёмка Фазы 0
- git-репо с baseline; калькулятор работает на Postgres (тесты зелёные).
- Деньги — decimal.
- Sentry ловит события backend (DSN из KV, не в коде).
- Backend+frontend задеплоены в ACA (или зафиксировано, что блокирует), RG с тегами, в watchdog.

## Статус
- [x] A.git — baseline на ветке chore/phase-0-foundation (2 коммита)
- [x] A.postgres — БД izigo/izigo_test, migrate:fresh + тесты зелёные, калькулятор на PG
- [x] A.decimal — pv/bv/binary_small_branch_volume/rank_bonus_amount → numeric(20,2)
- [~] B.sentry — SDK подключён (код). БЛОКЕР: нужен свой Sentry auth-token IziGo
      (создать на sentry.adarasoft.com; чужой токен переиспользовать нельзя) → создать проект,
      DSN → KV `izigo--beta--SENTRY-DSN` → в .env.
- [ ] C.azure — НЕ начато. Требует решений (имена RG/региона, GitHub-репо, деплоить ли
      промежуточный калькулятор) — чекпоинт перед созданием billable-ресурсов.
