# ТЗ: закрыть остаток из 7 dependabot-алертов (мажор-бампы)

**Дата:** 2026-07-20 · **Класс:** L (мажор рантайм-депа бота) · **Вход:** /spec → /armanda
**Предшественник:** PR #49 закрыл не-ломающие (12→7). Здесь — 7 оставшихся, требующих breaking-путей.

## Проблема
7 открытых dependabot-алертов, ни один не закрывается не-ломающим `npm audit fix`.
Локализация (по `manifest_path`):

| Пакет | Где | fixed_in | Природа |
|---|---|---|---|
| vite ×4 | `mh-calc-backend-main/Modules/ConfigIziGo/package.json` (devDep `vite ^4`) | 5.4.20 / 6.4.2-3 | dev-only, нет lockfile, в прод-CI НЕ билдится |
| postcss | frontend, транзитив под `next@15.5.20` | 8.5.10 | патч |
| uuid | frontend, под `react-d3-tree@3.6.6` (→ `uuid ^8.3.1`) | 11.1.1 | react-d3-tree@3.6.6 = latest, upstream не пофикшен |
| @opentelemetry/core | bot, под `@sentry/node@8.55.2` | 2.8.0 | закрывается бампом sentry→v10 |

## Scope (что делаем) — 3 независимые поверхности, отдельными PR

### PR-A — Бот: `@sentry/node` 8.55.2 → ^10 (major)
1. Бамп `mh-calc-bot/package.json` → `@sentry/node` на актуальный v10 (точный пин, стиль репо).
2. Реконсиляция кода инициализации Sentry (`src/`), если API 8→9→10 сменился (init/integrations/scope).
3. Закрывает алерт `@opentelemetry/core` (v10 тянет otel-core ≥2.8.0). Фронт уже на `@sentry/nextjs@10` — бот выравнивается.

### PR-B — Фронт: npm `overrides` (хирургия, без мажоров каркаса)
1. В `mh-calc-frontend-main/package.json` добавить `overrides`: `postcss` → `^8.5.10`, `uuid` → `^11.1.1`.
2. `npm install`, проверить что `react-d3-tree` работает с uuid v11 (uuid v11 сохраняет v4-генерацию).
3. НЕ трогаем `next@15` и НЕ заменяем `react-d3-tree`. Закрывает алерты postcss + uuid.

### vite ×4 — Dismiss (не PR, а GitHub-состояние)
1. Отметить 4 vite-алерта как dismissed, reason `not_used` (dev-тулинг ассетов Laravel-модуля,
   в прод-CI не собирается — grep не нашёл build ConfigIziGo в Dockerfile/start.sh; уязвимости
   только в `vite dev`-сервере, прод-exposure = 0).
2. Зафиксировать обоснование в этом ТЗ и в комментарии dismiss.

## Границы (что НЕ делаем)
- **`next` 15→16 НЕ трогаем** (postcss закрыт override'ом; Next 16 — отдельное решение, не ради transitive-патча).
- **`react-d3-tree` НЕ заменяем** (uuid закрыт override'ом; замена viz-пакета — большой риск для core-фичи дерева).
- **vite в ConfigIziGo НЕ апгрейдим** (dismiss; апгрейд `laravel-vite-plugin ^0.7.5→^2` ради несобираемого пайплайна — возня > польза).
- Движок `Modules/Calculator`, Laravel-версия, auth/платежи — не трогаем.

## Приёмка (да/нет)
1. **PR-A:** `@sentry/node` в lock = ^10; бот `npm test` 8/8 зелёный (вкл. `initSentry`-тест);
   доказано, что Sentry реально инициализируется/репортит (init с DSN не бросает + тест-событие ИЛИ verify пути).
2. **PR-B:** `overrides` в `package.json`; `npm run lint` (без новых ошибок) + `npm run build` зелёные;
   визуализация бинар-дерева (`react-d3-tree`) рендерится (smoke на собранной странице /miniapp или cabinet/tree).
3. **vite ×4:** dismissed с reason `not_used` + обоснование зафиксировано.
4. **Итог:** открытых dependabot-алертов `7 → 0` (или явно документированный остаток с причиной).
5. Каждый PR: CI-джоб `test` зелёный; **мердж = прод-деплой — только с явного «ок» владельца** (по PR отдельно).

## Риск-факторы
- **L**: мажор рантайм-депа бота (@sentry/node 8→10) — риск, что ошибки бота перестанут репортиться → verify обязателен.
- Фронт override uuid 8→11 — риск несовместимости с react-d3-tree → ловится на build/рендере дерева, fallback = замена пакета (тогда эскалация к владельцу).
- Прод-деплой ×2 (бот, фронт) — по одному, с «ок».
