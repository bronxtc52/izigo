# План: миграция izigo-фронта на Next 15 (к ТЗ 2026-07-09)

## Шаги

1. **Ветка** `feat/frontend-next15` от свежего main.
2. **Executable-приёмка до кода:** скрипт `mh-calc-frontend-main/scripts/smoke.sh` —
   кодифицирует критерии ТЗ №2–5: docker build → run → curl 5 маршрутов (200) +
   grep SSR-ошибок (0) + tonconnect-манифест (валидный JSON) + host-роутинг
   (Host: admin.* → /admin; обычный → /admin недоступен) + GA-гигиена
   (нет googletagmanager в /admin/login). Прогнать на main → зелёный (baseline),
   закоммитить отдельным коммитом.
3. **Peer-аудит ДО бампа** (must-fix совета, product_risk-2): для каждой React-зависимости
   (`@tonconnect/ui-react`, `antd`, `@ant-design/icons`, `react-i18next`, `react-d3-tree`,
   `react-transition-group`, `react-copy-to-clipboard`, `@sentry/nextjs`) снять
   `npm view <pkg>@latest peerDependencies` и зафиксировать таблицей в PR: поддерживает ли
   React 19 и какой версией. **`--legacy-peer-deps` для платёжно-критичных
   (`@tonconnect/ui-react`, `antd`) ЗАПРЕЩЁН** — нет поддержки React 19 → миграция блокируется,
   а не обходится. Для некритичных листовых пакетов обход допустим только при зелёном
   браузерном смоуке (шаг 5а) и фиксируется в PR.
3б. **Бампы зависимостей** (одним `npm install`): `next@^15.5`, `react@^19`, `react-dom@^19`,
   `eslint-config-next@^15`, `antd@^5` (свежий), `@ant-design/v5-patch-for-react-19` +
   бампы из peer-аудита.
4. **Код:**
   - `src/app/layout.js`: `export default async function RootLayout` + `await headers()`.
   - Импорт `'@ant-design/v5-patch-for-react-19'` — первой строкой в самом верхнем
     клиентском компоненте, оборачивающем приложение (найти провайдер/GlobalContext;
     если такого нет — маленький `src/app/react19-patch.js` ('use client') + импорт в layout).
   - `next.config.mjs`: удалить `experimental.instrumentationHook` и блок `i18n`
     (оба — с комментарием почему).
5. **Локальная верификация:** `npm run lint` → `scripts/smoke.sh` на ветке → зелёный.
   Sentry-обвязку проверить билдом (withSentryConfig на Next 15 — @sentry/nextjs 10.x поддерживает).
5а. **Браузерный смоук ДО мерджа** (must-fix совета, product_risk-1): headless-Chromium
   (официальный Playwright-образ в docker, БЕЗ установки на хост) против контейнера из ветки —
   на каждом маршруте (/, /miniapp, /cabinet, /cabinet/tree, /admin/login) страница загружается,
   **console-ошибок и unhandled rejections — 0**, на /miniapp монтируется TonConnect-провайдер
   (кнопка/manifest-запрос виден), на /admin/login отрисована форма логина. Это ловит runtime-краши
   React 19 (ref/findDOMNode-классы проблем), которые curl-смоук не видит. Прогнать и на baseline
   (main) для честного сравнения.
   *Осознанная граница:* полного стейджинга нет и не создаём (новый платный Azure-ресурс —
   красная зона); реальную TON-транзакцию и submit форм админки прогоняет владелец руками
   **сразу после деплоя** (окно объявляется, откат — revert+CI, отработан 2026-07-09, либо
   активация предыдущей ревизии из сессии владельца).
6. **Ревью:** внутренний reviewer-субагент (+ внешний по политике L — совет balanced,
   отдельная денежная карточка) → правки.
7. **PR** с полным описанием (breaking changes, что проверено, что проверить руками —
   TON-оплата, формы админки). Мердж — только по «ок» (прод-деплой), затем прод-смоук
   и проверка закрытия алертов #22/#40/#59/#60/#62/#20.

## Откат
Revert-PR + CI (отработан 2026-07-09), либо ACA-ревизия предыдущего образа из сессии владельца.

## Известные ловушки (заложены в план)
- antd v5 официально не поддерживает React 19 без патч-пакета → шаг 3/4.
- `experimental.instrumentationHook` в Next 15 не существует → билд-ворнинг/ошибка конфига.
- Nested vite/glob внутри eslint-тулинга уедут с eslint-config-next@15 (алерт #20).
- Прод-образ = multi-stage со `npm ci` → любые peer-хаки должны жить в package.json/lock,
  а не в локальной среде.
