# Редизайн кабинета партнёра IziGo → Telegram Mini App (mobile-first)

Статус: спека / вход для под-этапа после S3. UI-код тут НЕ пишется.
Дата: 2026-06-21. Стек: Next 14 (App Router), antd 5, axios, react-i18next.

## 0. Контекст и что уже есть

Текущий кабинет — desktop-стиль antd, сделан только что:

- `src/views/cabinet/CabinetLayout.js` — каркас: antd `Layout` + верхнее
  горизонтальное `Menu` (Доход / Команда / Ранг / Профиль / Калькулятор / Выход).
- `src/views/cabinet/Dashboard.js` — `/cabinet`. Карточки `Statistic` (всего + по типам
  бонусов: binary/referral/leader/rank), блок «Активация пакета» (кнопки Bronze/Silver/Gold),
  таблица «История начислений».
- `src/views/cabinet/TeamTree.js` — `/cabinet/tree`. `react-d3-tree` (dynamic, ssr:false),
  вертикальная ориентация, высота 600px.
- `src/views/cabinet/RankProgress.js` — `/cabinet/rank`. Текущий/следующий ранг +
  `Progress` по small_branch_pv и personal_count.
- `src/views/cabinet/Profile.js` — `/cabinet/profile`. Descriptions (имя/статус/ранг/
  реф-код) + реф-ссылка с копированием.
- `src/views/cabinet/api.js` — обёртки над API + `PACKAGES` (Bronze 90PV/$100,
  Silver 180PV/$200, Gold 540PV/$600) + `handleAuthError` (401/403 → разлогин).
- Роуты: `src/app/cabinet/{layout.js,page.js,tree,rank,profile}`.

Backend API (готов): `/api/v1/cabinet/{me,dashboard,rank-progress,team-tree,activate-package}`.

Инфраструктура фронта, важная для редизайна:

- **Авторизация сейчас**: токен `userToken` в `localStorage`, шлётся заголовком
  `CalculatorAuthToken` (`src/common/utils/utils.js` → `getData`/`sender`). Нет токена →
  `GlobalContext` показывает `LocalAuth` (email+пароль). См. `src/common/GlobalContext.js`.
- **ConfigProvider antd НЕ используется** (грепом не найден) — тему задаём впервые.
- Локали: `ru` присутствует (`src/locales/ru`), плюс az/kk/ky/mn/uz. Default lang в
  контексте — `kk`; для Mini App форсим `ru`.
- `react-d3-tree` — desktop-ориентированный, на узком экране почти неюзабелен (ключевой риск).

Наш канонический паттерн Mini App (KB `kb-telegram/references/telegram-miniapp-initdata.md`,
проект `tracker_artur`): фронт шлёт `X-Telegram-Init-Data`, backend валидирует HMAC и
монтирует отдельный `/miniapp`-роутер. Этот паттерн берём за основу.

---

## (A) БРИФ ДЛЯ OPEN-DESIGN

Передать в self-host open-design (http://10.8.0.1:7456, по VPN, BYOK в UI).
Дизайн идёт ТОЛЬКО через open-design; мы получаем HTML-макет каждого экрана, код пишем сами.

### Что это и общие требования

Кабинет партнёра MLM-проекта IziGo, открываемый КАК Telegram Mini App (внутри Telegram,
полноэкранно, web_app). Аудитория — партнёры-дистрибьюторы на смартфонах.

- **Mobile-first**, базовый макет 360–430px ширины (iPhone/Android). Desktop — не цель.
- **Telegram-нативный вид**: цвета берём из `themeParams` Telegram (см. ниже), а не хардкод.
  Светлая и тёмная схемы (`colorScheme` light/dark) — обе.
- **Нижний таб-бар** (bottom navigation, 4 вкладки) вместо верхнего меню. Иконка + подпись.
- **Компактные карточки**, крупные тапабельные зоны (min 44×44px), без таблиц во всю ширину.
- Учитывать **safe-area** (вырез/нав-бар): нижний таб-бар отступает на `safeAreaInset.bottom`,
  верх контента — на `contentSafeAreaInset.top`.
- Системную кнопку действия рисуем как **Telegram MainButton** (нативная кнопка снизу), а не
  обычную кнопку в потоке — заложить это в макетах активации.
- Локаль интерфейса — **ru** (все подписи на русском).
- Брендинг — IziGo (лого/акцент есть в `src/views/auth/IziGoLogo.js`), но акцентный цвет
  по возможности завязать на `themeParams.button_color`.

### Цветовые токены (themeParams Telegram → дизайн-токены)

Просим в макете использовать переменные, маппинг к Telegram:

| Дизайн-токен | Telegram themeParams | Назначение |
|---|---|---|
| surface/bg | `bg_color` | фон экрана |
| surface-secondary | `secondary_bg_color` | фон карточек/таб-бара |
| text | `text_color` | основной текст |
| text-muted | `hint_color` | подписи, второстепенное |
| accent | `button_color` / `link_color` | акцент, активный таб, прогресс |
| on-accent | `button_text_color` | текст на акцентной кнопке |
| destructive | `destructive_text_color` | выход, ошибки |

Тёмная схема — те же токены, значения приходят от Telegram автоматически.

### Экраны (нужен HTML-макет каждого)

1. **Дашборд / Доход** (главный, таб 1)
   - Hero-карточка «Всего начислено» (крупная сумма, $).
   - 2×2 сетка компактных карточек по типам бонусов: Бинарный / Реферальный / Лидерский /
     Ранговый (иконка + сумма).
   - Блок «Активация пакета»: 3 карточки-пакета (Bronze/Silver/Gold) с PV и ценой; активный
     помечен бейджем «активен». Выбор пакета → подтверждение через нижнюю MainButton
     «Активировать за $N» (макет показать и состояние «нет активного пакета» с подсказкой).
   - Свёрнутый список «Последние начисления» (карточки-строки: тип-тег + сумма + дата),
     ссылка «Показать все» (история отдельным под-экраном/шторкой). НЕ таблица.

2. **Команда / Дерево** (таб 2)
   - Mobile-friendly визуализация placement-дерева. Просим ДВА варианта в макете:
     (а) раскрываемый список-аккордеон по уровням (узел: имя/ID, статус-точка, PV, кол-во
     потомков; тап — раскрыть детей);
     (б) компактная зум/пан мини-схема как доп. режим.
   - Верхняя сводка: размер команды, объём малой/большой ветки.
   - Пустое состояние «В команде пока никого» + кнопка «Пригласить» (шарит реф-ссылку).

3. **Ранги / Прогресс** (таб 3)
   - Карточка текущего ранга (бейдж) и следующего.
   - Прогресс-бары: «Объём малой ветки X / Y PV» и «Лично приглашённые X / Y».
   - Доп-условие текстом («N приглашённых с рангом ≥ …»), если есть.
   - Состояние «максимальный ранг достигнут».

4. **Профиль / Реф-ссылка** (таб 4)
   - Карточка партнёра: имя, статус (тег), ранг, реф-код.
   - Блок реф-ссылки: поле + кнопки «Копировать» и «Поделиться» (Telegram share).
   - Пункт «Выход» (destructive). На узких экранах ссылку на «Калькулятор» вынести сюда.

### Что именно нужно от open-design

- Отдельный статичный **HTML+inline-CSS макет на каждый из 4 экранов** (+ под-экран «вся
  история начислений»), mobile-viewport, с нижним таб-баром и заглушкой под Telegram MainButton.
- Light и dark варианты (или один макет на CSS-переменных, переключаемый).
- Использование CSS-переменных под маппинг themeParams из таблицы выше (имена — на выбор
  дизайнера, но единообразно), чтобы мы подменили их значениями из Telegram.
- Готовые состояния: загрузка (скелетоны), пустые состояния, активный/неактивный пакет.
- Это reference-макет для верстки на antd 5 — не обязан быть на antd, но компонентный состав
  (карточки, прогресс, теги, таб-бар) должен ложиться на antd-примитивы.

---

## (B) ПЛАН РЕАЛИЗАЦИИ В НАШЕМ СТЕКЕ

### Решение 1: отдельная route-группа `(miniapp)`, а не адаптив `/cabinet`

Делаем **новую route-группу** `src/app/(miniapp)/` с собственным layout, НЕ перекраиваем
существующий `/cabinet` (он остаётся desktop-входом). Причины:

- Разные модели авторизации: `/cabinet` — `CalculatorAuthToken` + LocalAuth; Mini App —
  `initData`/`X-Telegram-Init-Data`, без формы логина. Их `layout`/контекст не совместимы.
- Mini App нужен свой root: `telegram-web-app.js`, `expand()`, тема из `themeParams`,
  MainButton/BackButton — это глобальные побочные эффекты, грязнить ими калькулятор нельзя.
- Бизнес-логику вьюх переиспользуем (общие хелперы/типы), но презентацию делаем заново
  mobile-first. Существующие `src/views/cabinet/*` НЕ меняем.

Роуты: `/(miniapp)/miniapp` (дашборд), `/miniapp/team`, `/miniapp/rank`, `/miniapp/profile`.
(Базовый сегмент `/miniapp` совпадает с backend-конвенцией `/miniapp`-роутера.)

### Решение 2: интеграция Telegram WebApp SDK

- **Подключение скрипта**: `<script src="https://telegram.org/js/telegram-web-app.js">` через
  `next/script` (`strategy="beforeInteractive"`) ТОЛЬКО в layout группы `(miniapp)` —
  не в root `layout.js` (чтобы не грузить в калькуляторе).
- **Инициализация** — клиентский провайдер `TelegramProvider`:
  - `const tg = window.Telegram.WebApp;`
  - `tg.ready();` затем `tg.expand();` (на весь экран).
  - Прочитать `tg.colorScheme`, `tg.themeParams`, подписаться на событие `themeChanged`.
  - Прочитать `tg.initData` (для API) и `tg.initDataUnsafe.user` (для UI, НЕ доверять).
  - Прочитать `tg.viewportStableHeight`, `tg.safeAreaInset`, `tg.contentSafeAreaInset` →
    прокинуть в CSS-переменные (`--tg-safe-bottom` и т.п.) на корневом контейнере.
  - Деградация: если `window.Telegram?.WebApp?.initData` пуст (открыто вне Telegram) —
    показать заглушку «Откройте через Telegram» (в dev — флаг для мок-initData).
- **themeParams → antd ConfigProvider**: оборачиваем дерево в `ConfigProvider` (впервые в
  проекте). Маппинг:
  - `algorithm`: `theme.darkAlgorithm` если `colorScheme === 'dark'`, иначе `defaultAlgorithm`.
  - `token.colorPrimary = themeParams.button_color`;
  - `token.colorBgBase = themeParams.bg_color`;
  - `token.colorTextBase = themeParams.text_color`;
  - `token.colorBgContainer = themeParams.secondary_bg_color`;
  - `token.colorLink = themeParams.link_color`.
  - Пересобирать тему при `themeChanged`.
- **MainButton** — для активации пакета: при выборе пакета `tg.MainButton.setText('Активировать
  за $N'); tg.MainButton.show(); tg.MainButton.onClick(handler)`; `showProgress()` на время
  запроса; `hide()` после успеха/на экранах без действия. Хук `useMainButton({text,onClick,visible})`
  с очисткой `offClick`/`hide` в cleanup.
- **BackButton** — на под-экранах (история начислений, детали узла дерева):
  `tg.BackButton.show()` + `onClick(router.back)`; `hide()` на корневых табах.
- **Haptics** (опц.) — `tg.HapticFeedback` на тап таб-бара/активацию.

### Решение 3: авторизация и API через initData

- Новый axios-инстанс `miniApi` (по образцу KB): интерсептор добавляет заголовок
  `X-Telegram-Init-Data` = `window.Telegram.WebApp.initData` в каждый запрос. Bearer/
  `CalculatorAuthToken` тут НЕ используем.
- Новый слой API `src/views/miniapp/api.js`: те же ресурсы
  (`me/dashboard/rank-progress/team-tree/activate-package`), но через `miniApi`.
- **Backend-зависимость (вне фронта, добавить в задачу backend)**: смонтировать
  `/api/v1/cabinet/miniapp/*` (или принять `X-Telegram-Init-Data` на существующих cabinet-
  эндпоинтах) с валидацией HMAC по `kb-telegram` (secret = HMAC("WebAppData", BOT_TOKEN),
  `data_check_string` из отсортированных пар без hash, `compare_digest`, `MAX_AUTH_AGE`),
  резолв партнёра по `tg_user.id` (привязка telegram_id → member). Без этого фронт не
  заработает — это блокер, согласовать ДО старта верстки.
- Реф-ссылку (`ref_link` из `/me`) и кнопку «Поделиться» завязать на Telegram share / deep-link
  бота (`https://t.me/<bot>?start=<ref_code>`), а не на веб-URL калькулятора.

### Решение 4: mobile-first навигация

- Свой `MiniAppLayout`: контент + фиксированный **нижний таб-бар** (4 вкладки), активный таб
  по `usePathname`. Верхнего `Header`-меню НЕТ.
- Таб-бар отступает на `safeAreaInset.bottom`; контент имеет `padding-bottom` под таб-бар +
  под MainButton, когда тот виден.
- Дерево команды: заменить голый `react-d3-tree` на mobile-режим (аккордеон-список по
  уровням как основной; зум/пан-схема — опционально через тот же react-d3-tree в контейнере
  с жестами). Это самый трудозатратный экран.

### Список компонентов к созданию (ничего из существующего не правим)

Создать:
- `src/app/(miniapp)/layout.js` — подключение SDK (next/script) + `TelegramProvider` + `MiniAppLayout`.
- `src/app/(miniapp)/miniapp/page.js`, `.../team/page.js`, `.../rank/page.js`, `.../profile/page.js`.
- `src/views/miniapp/TelegramProvider.js` — init, expand, theme, safe-area, контекст `useTelegram()`.
- `src/views/miniapp/MiniAppLayout.js` — каркас + нижний таб-бар.
- `src/views/miniapp/BottomTabBar.js`.
- `src/views/miniapp/hooks/useMainButton.js`, `useBackButton.js`.
- `src/views/miniapp/api.js` + `src/common/utils/miniApi.js` (axios-инстанс с интерсептором).
- Экраны: `MiniDashboard.js`, `MiniTeam.js`, `MiniRank.js`, `MiniProfile.js`,
  `MiniEarningsHistory.js` (под-экран с BackButton).
- Карточки-примитивы: `StatCard.js`, `PackageCard.js`, `TeamNodeRow.js`.

Править (минимально, опционально):
- `src/views/cabinet/api.js` — вынести `PACKAGES`/`TYPE_LABEL` в общий модуль для
  переиспользования (если решим не дублировать). Сами cabinet-вьюхи НЕ трогаем.

### Риски

1. **Backend initData-валидация — блокер.** Без `/miniapp`-эндпоинтов и привязки
   telegram_id↔member фронт нерабочий. Согласовать первым.
2. **Привязка аккаунта**: как partner-аккаунт связывается с telegram_id (через
   `?start=` deep-link / разовый код / в боте). Нужен продуктовый ответ.
3. **Дерево на мобайле**: `react-d3-tree` плохо тянется на узкий экран — переписываем
   презентацию, это основной объём работ.
4. **ConfigProvider впервые**: проверить, что глобальные antd-стили калькулятора не ломаются
   (изоляция в группе `(miniapp)` снимает риск).
5. **SSR/`window`**: весь Telegram-код — client-only, `next/script` + guards на `window`.
6. **Открытие вне Telegram**: явная заглушка, иначе пустой/битый экран.
7. **Тёмная тема**: контраст IziGo-акцента на `bg_color` обеих схем — проверить вручную.
8. **Локаль**: форсить `ru` независимо от `lang` калькулятора (дефолт там `kk`).
9. **Sentry** (правило observability): подключить `@sentry/nextjs`, если ещё не подключён в
   проекте, до прод-выката Mini App.

### Тест-чеклист

- [ ] `tg.ready()`/`expand()` вызываются один раз; приложение на весь экран.
- [ ] Light/dark: цвета antd соответствуют `themeParams`; реакция на `themeChanged`.
- [ ] Safe-area: таб-бар и MainButton не перекрывают контент на устройстве с вырезом.
- [ ] Каждый запрос несёт `X-Telegram-Init-Data`; backend принимает валидную, отвергает
      подделанную/протухшую (401).
- [ ] Дашборд: суммы и типы бонусов совпадают с `/dashboard`; история — корректные данные.
- [ ] Активация: MainButton «Активировать за $N» → запрос → успех → пакет «активен»,
      `showProgress` во время запроса; повторная активация активного пакета заблокирована.
- [ ] Команда: аккордеон раскрывается по уровням; пустое состояние; сводка по веткам верна.
- [ ] Ранги: прогресс-бары и доп-условие корректны; состояние «макс. ранг».
- [ ] Профиль: реф-ссылка/код корректны; «Копировать» и «Поделиться» работают.
- [ ] BackButton: на под-экранах виден и возвращает; на корневых табах скрыт.
- [ ] Открытие вне Telegram → заглушка, без крэша.
- [ ] Калькулятор и `/cabinet` не затронуты (регресс).
- [ ] Sentry ловит ошибки Mini App.

---

_Вход для под-этапа после S3. Перед версткой: (1) получить HTML-макеты из open-design,
(2) подтвердить backend `/miniapp`-валидацию и привязку telegram_id↔member._
