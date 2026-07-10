# ТЗ: миграция фронтенда izigo на Next.js 15

**Дата:** 2026-07-09 · **Issue:** #41 · **Каталог:** `mh-calc-frontend-main` · **Размер:** M

## Цель
Закрыть 5 high Dependabot-алертов по `next` (#22, #40, #59, #60, #62 — фиксы в 15.0.8–15.5.16,
бэкпортов в 14.x нет) + #20 `glob` (пинован `eslint-plugin-next@14`), переведя фронт
с Next 14.2.35 на **Next 15.5.x** без изменения поведения Mini App и админки.

## Объём изменений (по разведке кода)
1. **Зависимости:** `next` → `^15.5`, `react`/`react-dom` → `^19` (App Router в 15 требует React 19),
   `eslint-config-next` → `^15`, `antd` 5.21 → свежий 5.x + **`@ant-design/v5-patch-for-react-19`**
   (официальный шим совместимости antd v5 с React 19), импорт шима один раз в корневом клиентском входе.
2. **Async request APIs:** единственная точка — `src/app/layout.js:66` `const h = headers()`
   → `async function RootLayout` + `await headers()`. `params`/`searchParams`/`cookies()` в коде не используются.
3. **next.config.mjs:**
   - удалить `experimental.instrumentationHook` (в 15 instrumentation стабильна, ключ не распознаётся);
   - удалить мёртвый блок `i18n` (Pages Router-опция; App Router её игнорирует — `/ru` уже сейчас 404,
     реальная i18n живёт в `src/common/i18n.js` через react-i18next).
4. **Route handler** `tonconnect-manifest.json/route.js`: в 15 GET по умолчанию не кэшируется —
   для манифеста, читающего env, это корректно; изменений не требуется (проверить смоуком).
5. **Прочие React-peer зависимости** (`@tonconnect/ui-react`, `react-d3-tree`,
   `react-transition-group`, `react-copy-to-clipboard`, `react-i18next`): бампить только если
   `npm install` упрётся в peer-конфликт с React 19; фиксировать каждое отступление в PR.

## Что НЕ делаем
- Не переписываем клиентский код/страницы, не меняем поведение middleware и аналитики.
- Не вводим CSP/X-Frame-Options (осознанное решение G2 — Mini App в iframe Telegram).
- Не трогаем бэкенд и модуль ConfigIziGo (Laravel — issue #42).
- Не переходим на Turbopack в проде.

## Критерии приёмки (проверяемые)
1. `npm run build` в docker-сборке проходит; в образе Next **15.5.x** (`npm ls next`).
2. Docker-смоук на чистом контейнере: `/`, `/miniapp`, `/cabinet`, `/cabinet/tree`,
   `/admin/login` → **200**; `element type is invalid|unhandled|⨯` в логах SSR — **0**.
3. `GET /tonconnect-manifest.json` → 200, валидный JSON с `url`/`name`/`iconUrl`.
4. Хост-роутинг middleware жив: запрос с `Host: admin.izigo.adarasoft.com` на `/` →
   redirect/rewrite в `/admin`; обычный хост на `/admin` → редирект в `/miniapp`.
5. Гигиена аналитики сохранена: HTML ответа на `/admin/login` **не содержит**
   `googletagmanager`/`mc.yandex`, HTML `/miniapp` при `NEXT_PUBLIC_SERVER_PROD=true` — содержит.
6. `npm run lint` проходит (eslint-config-next 15).
7. После мерджа: Dependabot-алерты #22/#40/#59/#60/#62/#20 → fixed; прод-смоук тех же маршрутов 200.

## Риски
- **antd + React 19** — главный риск; митигируется официальным патч-пакетом и смоуком всех
  ключевых экранов. Ключевые интерактивы (TON-оплата, формы админки) кликом не проверяем —
  отметить владельцу проверить руками после деплоя.
- Мердж в main = прод-деплой (CI с тест-гейтом); откат отработан (revert + CI, как 2026-07-09).
