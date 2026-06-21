# План: Фаза 1 ц2 + Фаза 2 — реальная сеть + кабинет + админка

**ТЗ:** `docs/specs/2026-06-20-cabinet-admin-phase1c2.md`. (Гейт 2 — план, без кода.)
Архив предыдущего цикла — в конце файла.

## Архитектурные решения (Гейт 2)

- **Хранение дерева:** placement (бинар) — **Postgres `ltree`** (materialized path) +
  `parent_id`/`position(L|R)`; sponsorship (ЛП) — `sponsor_id` self-FK. ltree даёт быстрые
  ancestor/descendant запросы (upchain бинара, объём малой ноги, BFS-спилловер). Запросы дерева
  изолируем в репозитории → при проблемах с расширением фолбэк на closure table без переписи слоёв.
  ⚠️ Требует `CREATE EXTENSION ltree` (на Azure Flexible — добавить в `azure.extensions` allowlist).
- **Стыковка с ядром:** новый `Repository/EloquentNetworkRepository` строит in-memory `Network`
  (`MemberNode`) из таблиц members → отдаёт в существующий `CompensationEngine` (ядро не трогаем).
  `PlanRepository` строит доменный `Plan` из БД-настроек (фолбэк — `IziGoPlanFactory`).
- **Активация (мок):** `activation_events` (unique `idempotency_key`) → полный пересчёт сети
  движком → снимок результата в `member_bonus_lines` + агрегат `member_earnings` (для дашборда).
  Без ledger/денег. Пересчёт всей сети — ок для MVP-объёма; инкремент — позже.
- **RBAC:** **без новой зависимости** — таблицы `roles`/`role_user` (4 фикс-роли) + Laravel Gates/Policies
  и middleware `role:*`. (Альтернатива spatie/laravel-permission отклонена — лишняя зависимость под 4 роли.)
- **Размещение:** `PlacementStrategy` интерфейс → `AutoSpilloverStrategy` (слабая нога, BFS) +
  `ManualStrategy` (валидация слота в своём поддереве); режим — настройка компании в `plan_settings`.
  Конкурентность: `SELECT … FOR UPDATE` на родительском слоте + `version`.

## Схема БД (новые миграции, Postgres)

- `members`: `id, calculator_user_id(FK,nullable), sponsor_id(FK members,nullable),
  parent_id(FK members,nullable), position(enum left|right,nullable), path(ltree),
  package_id(FK,nullable), rank_id(FK,nullable), status(registered|active), version(int),
  timestamps`. GIST-индекс на `path`.
- `activation_events`: `id, member_id(FK), package_id(FK), idempotency_key(unique),
  status, created_at`.
- `member_bonus_lines`: `id, recipient_member_id(FK), type(binary|referral|leader|rank),
  amount(decimal), basis(jsonb — объяснение/формула), source_event_id(FK), calculated_at`.
- `member_earnings`: `id, member_id(FK,unique), total(decimal), by_type(jsonb), updated_at` (снимок).
- `plan_settings`: `id, key, value(jsonb)` — проценты/пороги/`placement_mode` (сид из `IziGoPlanFactory`).
- `roles` (owner|finance|leader|support) + `role_user` (pivot). Опц. `leader_scope_member_id` для лидера.

## Декомпозиция на под-этапы (поставляем вертикально; каждый — свой Гейт 3→4)

### S1 — Реальная сеть (фундамент, backend)  [приоритет, всё зависит от него]
1. Миграция: enable `ltree`; таблицы members/activation_events/bonus_lines/earnings/plan_settings/roles.
2. Eloquent: `Member`, `ActivationEvent`, `MemberBonusLine`, `MemberEarning`, `PlanSetting`, `Role`.
3. `Domain/Repository/NetworkRepository` (интерфейс) + `EloquentNetworkRepository` (БД→`Network`).
   `PlanRepository` (БД→доменный `Plan`, фолбэк фабрика).
4. `PlacementService` + `PlacementStrategy`(Auto/Manual) + конкурентная безопасность.
5. `ActivationService`: идемпотентное событие → `CompensationEngine->calculate(Network)` → снимок
   в bonus_lines/earnings.
6. Регистрация→размещение: расширить `LocalAuthService.register` (sponsor ref + placement).
7. Тесты: размещение (оба режима), идемпотентность активации, маппер БД→Network эквивалентен
   golden-сценариям ядра, конкурентная постановка.

### S2 — Кабинет партнёра (web)
8. API `/api/v1/cabinet/*`: `me` (профиль+реф-ссылка), `dashboard` (разбивка дохода+логика),
   `rank-progress`, `team-tree` (поддерево для d3), `activate-package` (мок).
9. Next route-group `src/app/(cabinet)/`: layout с навигацией, страницы dashboard/tree/rank/profile.
   Переиспользовать react-d3-tree (`Structure.js`), antd, API-враппер (`CalculatorAuthToken`), i18n.
10. Тесты API кабинета + сценарий регистрация→активация→доход виден.

### S3 — Админка + RBAC (web)
11. RBAC: `roles`/`role_user`, Gates/Policies, middleware `role:*`, сидер ролей, назначение роли.
12. API `/api/v1/admin/*`: `members` (поиск/фильтр), `members/{id}`, `members/{id}/role`,
    `plan-settings` (GET/PUT), `tree` (лидер — своя ветка).
13. Next route-group `src/app/(admin)/`: список участников, настройка плана, дерево; гейтинг по роли.
14. Тесты: гейты ролей (каждая видит только разрешённое), редактирование плана влияет на расчёт.

### S4 — Telegram (бот + Mini App)
15. Бот-воркер (отдельный сервис на ACA без ingress): онбординг, deep-link приглашения,
    уведомления (новый реферал/начислен бонус/достижение ранга). Токен из KV
    `izigo--beta--TELEGRAM-BOT-TOKEN` (managed identity). LLM/markdown→Telegram-HTML (глоб. правило).
16. Mini App: route-group `src/app/(miniapp)/` + backend-валидация `initData` (HMAC-SHA256),
    переиспользовать UI кабинета.
17. Тесты: валидация initData, доставка уведомлений (мок), deep-link регистрация.

## Чек-лист
- [x] S1 реальная сеть  [x] S2 кабинет  [x] S3 админка+RBAC  [x] S4 Telegram

### ДЕПЛОЙ НА ПРОД — ВЫПОЛНЕН (2026-06-21, по гейтам)
- Гейт 1: `ltree` в `azure.extensions` allowlist на `izigo-pg-beta` (динамически, без рестарта).
- Гейт 2–3: коммит S4c + push → CI зелёный, backend+frontend выкачены на ACA.
- Гейт 4: миграции применены на проде (`start.sh: migrate --force`) — ltree + 8 таблиц DONE.
  Smoke прод: /cabinet→403, /miniapp→401, /admin→403, /packages→200.
- Гейт 5: бот `@Izigopro_mlm_bot` ЖИВ — новый ACA-сервис `ca-izigo-bot` (без ingress, single-replica,
  identity `id-izigo` + `AZURE_CLIENT_ID`, токен из KV, menu-кнопка Mini App). Уведомления включены
  на backend (`TELEGRAM_NOTIFY_ENABLED=true`, токен через ACA keyvaultref `tg-bot-token`).
- Запуск Mini App: ТОЛЬКО из Telegram (бот → /start → «Открыть IziGo» или menu-кнопка). Прямой URL
  в браузере by design показывает «Откройте через Telegram» (initData пуст).

Остаточные хвосты (не блокируют):
- Sentry-проект `izigo` + DSN в KV `izigo--beta--SENTRY-DSN` (бот: sentry=false; backend SDK есть).
- server-watchdog: добавить `rg-izigo-beta-neu` в `AZURE_RESOURCE_GROUPS` на mh-central.
- BotFather: опц. зарегистрировать Mini App как `t.me/bot/<app>` для `startapp=`-инвайтов.

### S4c — статус: КОД ГОТОВ (Гейт 4); деплой на approval
Архитектура: **входящее** — отдельный grammY-воркер (`mh-calc-bot/`); **исходящее** — backend шлёт
в Telegram Bot API напрямую (без второго shared-secret/HTTP-seam).
**Бот-воркер `mh-calc-bot/` (Node ESM, grammY 1.30):** /start (deep-link payload), /app, /help,
WebApp-кнопка запуска Mini App, меню команд. Токен — ТОЛЬКО из Key Vault (`@azure/identity`
DefaultAzureCredential + `@azure/keyvault-secrets`), не из env. Dockerfile (long-polling, без ingress),
README. Тесты: 4 (node --test, экранирование/тексты). `node --check` + импорт grammY — ок.
**Backend уведомления:** `TelegramNotifier` (best-effort, opt-in флаг `telegram_notify_enabled` +
токен из KV; ошибки доставки не ломают активацию), `TelegramNotifications` (ru, HTML-экранирование),
хук в `ActivationService` (post-commit): подтверждение активации партнёру + ранг-ап + «новый реферал»
спонсору. Тесты: TelegramNotifierTest (4, Http::fake — выкл/вкл/активация/экранирование).
Итог backend: 61 passed (188 assert); бот: 4 passed.

**ДЕПЛОЙ (НЕ сделано, на approval):** новый ACA-сервис для бота (без ingress, single-replica,
managed identity → доступ к kv-bronxtc-dev), `MINI_APP_URL`, в проде `TELEGRAM_NOTIFY_ENABLED=true`,
RG в server-watchdog. + ltree extension allowlist (S1). Деплой остального стека — тоже отдельно.

Ревью S4c (reviewer): P0 нет. Исправлено — graceful stop (await bot.stop) + try/catch boot,
config-комментарий «токен в проде из KV через ACA keyvaultref, не plain env», предупреждение
в notifier не логировать токен; тесты best-effort при ошибке Telegram + конструирование бота без URL.
Sentry в боте: ПОДКЛЮЧЁН (`@sentry/node`, best-effort, DSN из KV `izigo--beta--SENTRY-DSN`;
без DSN не включается). Остаётся при деплое: создать Sentry-проект izigo + положить DSN в KV.
Долг S4c: команды /balance,/ref через backend (сейчас всё в Mini App); MainButton/BackButton; /start-payload
не пробрасывает ref в Mini App (инвайт идёт через startapp= — принятый дефолт); дизайн Mini App под
open-design макет; 3 moderate npm-vuln в транзитивных депах бота (audit при деплое).

### S4 — статус: S4a+S4b ГОТОВО (Гейт 4 пройден); S4c отложен
**S4a backend (готово):** `TelegramInitData` (HMAC-SHA256, secret=HMAC("WebAppData",token), timing-safe
hash_equals, строгий auth_date/replay); токен ТОЛЬКО из конфига/KV (`izigo--beta--TELEGRAM-BOT-TOKEN`,
не в git). `telegram_id` на members (миграция). `MiniAppAuth` — резолв/Telegram-нативная регистрация
участника по telegram_id (атомарно: гонка уникального индекса → переиспользование). `MiniAppController`
+ маршруты `/api/v1/miniapp/*` (БЕЗ web-токена; auth по initData) reuse CabinetService. start_param→спонсор.
**S4b frontend (готово):** route `/miniapp` (бай-пас web-гейта только для него), Telegram WebApp SDK
(useTelegram: ready/expand/themeParams, поллинг загрузки), antd ConfigProvider от themeParams,
mobile-first нижний таб-бар, панели доход/команда(аккордеон-дерево)/ранг/профиль, активация. Сборка зелёная.
**Тесты:** TelegramInitDataTest (7) + MiniAppTest (8). Итог backend: 57 passed (183 assert).
Ревью (reviewer): HMAC корректен/timing-safe. Исправлено — P0 гонка авто-создания (catch unique→reuse,
+тест), пустой токен бота⇒401 (+тест), строгий auth_date (+тест), фронт различает 401/сервер-ошибку.

**S4c — НЕ сделано (на твоё подтверждение):** Telegram-бот-воркер (онбординг, deep-link, уведомления:
реферал/бонус/ранг) — отдельный сервис на ACA, токен из KV. Требует: выбор стека (grammY/Node vs
Laravel-команда), инфра-approval (новый контейнер/ревизия), и продуктовое решение по связке
web-аккаунт↔telegram_id (сейчас Mini App = отдельная Telegram-нативная идентичность).

Долг S4 (осознанно): уведомления бота (LLM-текст→Telegram-HTML по глоб. правилу — при появлении);
MainButton/BackButton (сейчас обычные кнопки); дизайн Mini App под макет open-design (бриф передан,
макета ещё нет); start_param-спонсор без доп. верификации (принятый дефолт — подтвердить бизнесом).

### S3 — статус: ГОТОВО (Гейт 4 пройден)
Backend RBAC: `roles`/`role_user` (S1), `CalculatorUser::roles/hasAnyRole/isOwner/leaderScopeMemberId`,
`RoleMiddleware` (alias `calculator.role`, owner проходит всегда), `AdminService` (список/поиск
участников с охватом лидера, карточка, assign/revoke роли, get/update plan-settings),
`AdminController` + маршруты `/api/v1/admin/*` с гейтами. Frontend: `src/app/admin/*` +
`src/views/admin/*` (сайдбар, MembersList с поиском/403, MemberCard с деревом ветки и назначением
роли, PlanSettings с режимом размещения). Сборка Next зелёная (3 маршрута admin).
Тесты: AdminTest (6, вкл. охват лидера и спилловер-стороннего). Итог backend: 42 passed (157 assert).
Ревью (reviewer): исправлено — P0 охват лидера = СПОНСОРСКАЯ линия (sponsor_id), не placement
(спилловер-стрейнджер закрыт тестом); валидация rank_bonuses (numeric>=0); leader-scope обязателен
и только для роли leader; фронт-обработка 401 в админ-вьюхах.
Дизайн: бриф админ-портала передан в open-design (http://10.8.0.1:7456); текущая вёрстка
функциональная на antd, визуал подгоним под макет.

Долг S3 (осознанно): редактирование процентов/порогов рангов из UI (сейчас editable только
placement_mode + rank_bonuses через API; пороги read-only) — довести при расширении PlanRepository;
descendantIds BFS по sponsor_id (N+1) → на ltree/closure при росте; per_page cap.

### S4 — Telegram (бот + Mini App) — план (автономная сессия)
Из спеки фон-агента `mh-calc-frontend-main/docs/specs/2026-06-21-cabinet-telegram-redesign.md`:
- **S4a (backend, делаю):** валидатор Telegram `initData` (HMAC-SHA256, secret=HMAC(bot_token,"WebAppData")),
  токен ТОЛЬКО из конфига/KV `izigo--beta--TELEGRAM-BOT-TOKEN`; `telegram_id` на members; MiniApp-
  middleware (резолв участника по telegram_id) + `/api/v1/miniapp/*` (reuse CabinetService) + линковка
  telegram_id к web-аккаунту. Тесты валидатора (валидный/подделка) и эндпоинтов.
- **S4b (frontend, делаю):** route-группа `(miniapp)`, Telegram WebApp SDK (expand/themeParams→antd
  ConfigProvider/MainButton/BackButton), mobile-first таб-бар, переиспользование cabinet-логики.
- **S4c (бот-воркер + ДЕПЛОЙ) — НЕ делаю автономно:** требует выбора стека и инфра-approval
  (отдельный сервис на ACA, токен из KV). Оставляю на подтверждение пользователя.
- Открытый продуктовый вопрос: как telegram_id связывается с партнёром (deep-link `?start=`,
  разовый код, линковка из web). Дефолт автономно: линковка из авторизованного web-кабинета.

### S2 — статус: ГОТОВО (Гейт 4 пройден)
Backend: `CabinetService` + `CabinetController` + маршруты `/api/v1/cabinet/*`
(me/dashboard/rank-progress/team-tree/activate-package), участник резолвится из токена
(изоляция, без IDOR). Frontend (Next App Router): route-группа `src/app/cabinet/*` +
`src/views/cabinet/*` (Dashboard с разбивкой дохода и активацией, TeamTree на react-d3-tree,
RankProgress, Profile с реф-ссылкой), навигация, редирект на /cabinet после входа, подхват
`?ref=` в регистрации. Сборка Next зелёная (4 маршрута кабинета).
Тесты: CabinetTest (8, вкл. изоляцию данных и 403 без токена). Итог backend S1+S2: 35→ зелёные.
Ревью (reviewer): P0 нет. Исправлено: фронт-обработка 401/403 (сброс токена→форма входа во
всех вьюхах), показ условия personal_in_rank, тест изоляции партнёров.

Долг S2 (осознанно, не блокирует):
- `personalCount` в прогрессе рангов — сетевой счётчик; доменная квалификация (RankSnapshot)
  считает в placement-поддереве с темпоральной отсечкой → точный прогресс в S3.
- teamTree — рекурсия N+1 по parent_id; перевод на ltree-префикс (`path`) при росте.
- rankProgress/recompute грузят/пересчитывают всю сеть; инкремент — позже.
- i18n кабинета: литеральные RU-строки (6 локалей подключить в полировке).
- Активация допускает смену пакета (downgrade) — бизнес-решение зафиксировать в S3.

### S1 — статус: ГОТОВО (Гейт 4 пройден)
Реализовано: 7 миграций (ltree+members+events+bonus_lines+earnings+plan_settings+roles),
модели, доменные интерфейсы NetworkRepository/PlanRepository + Eloquent-реализации (маппер
БД→Network, Plan из настроек+фабрики), PlacementService (auto/manual + переключатель компании),
ActivationService (идемпотентная активация → пересчёт ядром → снимок), регистрация→размещение.
Тесты: MemberPlacementTest (5) + PackageActivationTest (5) зелёные; LocalAuth + golden ядра целы.
Ревью (reviewer): P0 (idempotency под конкуренцией → insertOrIgnore; orphan-член → транзакция
вокруг register) и P1-3 (единственный корень → partial unique index), P2-9 (деньги в decimal
строкой) — **исправлены**. Конкурентность: FOR UPDATE + unique(parent_id,position) + partial
unique корня + insertOrIgnore.

Отложено осознанно (долг, не блокирует S1):
- ltree `path` ведётся, но subtree/ancestor-запросы пока через parent_id-обход (driver-agnostic);
  перевод на ltree-операторы (`<@`) — оптимизация под рост, не для MVP-объёма.
- `version` (оптимистичная блокировка) зарезервирован, сейчас не используется (хватает FOR UPDATE).
- Тест-долг: реальная гонка (параллельные процессы), leader/rank в снимке — точечные значения
  бонусов в тестах суть golden (реферал $9, бинар→итог $22.5), маппер БД→ядро проверен ими.

ПРИМ.: legacy Unit/StructureTest и Feature/StructureTest падали ДО S1 (проверено git stash) —
зависят от сид-данных и удалённой колонки `calculator_user_tokens.email`; к S1 не относятся.

## Гейт 4 (на каждый под-этап)
reviewer (корректность, чистота ядра, RBAC-гейты, расхождения с ТЗ) → правки →
tester (миграции/тесты/сценарии) → ручной клик-тест.

---
---

# [АРХИВ] План: Фаза 1 / цикл 1 — чистое доменное ядро (PV)

ТЗ: `docs/specs/2026-06-20-mlm-core-extraction.md`. Калькулятор-витрину не трогаем.

## Структура (новый чистый namespace `Modules\Calculator\Domain`)

```
Modules/Calculator/Domain/
  ValueObject/
    Money.php            # USD в центах (int), сложение/процент, без float
    Pv.php               # PV в сотых (int)
    Percent.php          # проценты (basis points)
  Model/
    MemberNode.php       # чистый узел: id, parentId, sponsorId, packageId, rankId,
                         #   leftLeg/rightLeg, pvPersonal/pvGroup, carryover-объёмы
    Network.php          # дерево: map id->node, обходы (placement вверх, sponsors вверх)
  Plan/
    PlanConfig.php       # проценты/глубины/пороги (binary%, referral[pkg][lvl],
                         #   leader[lvl][pkg][rank], maxRankDiff, депт) — из массива/конфига
    RankCondition.php    # пороги ранга (малая ветка PV, personalCount, inRank)
    Package.php          # id, sort, pv
    Rank.php             # id, sort, alias, bonus
  Repository/
    PackageRepository.php  # interface getById/getAll
    RankRepository.php     # interface getOrderedBySort
  Bonus/
    BinaryBonusCalculator.php    # пайринг min-ноги PV + carryover/flush, % вверх
    ReferralBonusCalculator.php  # % от PV пакета, глубина 2, по спонсорам
    LeaderBonusCalculator.php    # bonus-on-bonus, compression MAX_RANK_DIFF=2 (+ фикс null-deref)
    RankBonusCalculator.php      # разовая при повышении ранга
  Rank/
    RankQualifier.php            # конъюнкция условий, темпоральная отсечка maxNodeId
  CompensationEngine.php         # оркестратор: событие(узел) -> volumes -> ranks -> bonuses
  Dto/
    BonusLine.php, CalculationResult.php   # результат (тип бонуса, получатель, сумма, основание)
```

## Шаги
1. [ ] Прочитать текущие сервисы (BonusBinary/Leader/Rank/Referral, RankCheck, RankService,
       Node, NodeForCheckRanks, CalculatorService) — зафиксировать ТОЧНЫЕ формулы/пороги.
2. [ ] Value Objects (Money/Pv/Percent) + тесты на арифметику.
3. [ ] Plan/PlanConfig + Package/Rank/RankCondition + интерфейсы репозиториев.
4. [ ] MemberNode + Network (чистая модель дерева, обходы, накопление PV).
5. [ ] 4 калькулятора бонусов (база PV) + RankQualifier. Фикс null-deref в Leader.
6. [ ] CompensationEngine (оркестратор, детерминированный, без БД/побочек).
7. [ ] Golden unit-тесты (Tests/Unit/Domain): пайринг с carryover, реферальный по уровням,
       лидерский с compression, квалификация рангов (7/14/36 узлов), кейс цепочки до корня.
       Ожидаемые значения пересчитаны под PV (Bronze 90 / Silver 180 / Gold 540).
8. [ ] Прогон: pure-тесты зелёные без БД; калькулятор-витрина и LocalAuthTest не сломаны.

## Гейт 4
reviewer (корректность формул, чистота от Laravel, расхождения с ТЗ) → правки →
tester (прогон unit-suite + проверка, что витрина жива) → ручной обзор.

## Чек-лист
- [x] VO  [x] Plan  [x] Network  [x] калькуляторы+квалификатор  [x] движок  [x] golden-тесты (12 зелёных)
- Ревью: без P0; P1/P2 закрыты. Витрина и LocalAuthTest целы.

## Статус Фазы 1 / цикл 1: ГОТОВО (закоммичено, ветка chore/phase-0-foundation)
Следующий цикл Фазы 1: нормализованная генеалогия (Postgres ltree/closure) + реальные члены
сети + перевод витрины на ядро + API/кабинет.

## ОБЛАКО: ЗАДЕПЛОЕНО ✅ (Azure Container Apps, rg-izigo-beta-neu)
- GitHub: bronxtc52/izigo (push прошёл после создания репо пользователем).
- CI (GitHub Actions, OIDC→ACR) собирает образы из репо; первый деплой — apps созданы вручную
  с готовыми образами, дальше CI `update` обновляет их по push.
- Backend: https://ca-izigo-backend.livelycoast-2b4dcf83.northeurope.azurecontainerapps.io
- Frontend: https://ca-izigo-frontend.livelycoast-2b4dcf83.northeurope.azurecontainerapps.io
- Postgres Flexible B1ms (izigo-pg-beta) + БД izigo; секреты (DB pass, APP_KEY) — Key Vault
  через managed identity id-izigo. Проверено: пакеты Bronze/Silver/Gold, форма IziGo — 200.

### Остаточная полировка (не критично)
- Sentry: создать проект izigo + DSN в KV izigo--beta--SENTRY-DSN (нужен свой токен) → задеплоить env.
- server-watchdog: добавить rg-izigo-beta-neu в AZURE_RESOURCE_GROUPS (на mh-central).
- [x] Azure Monitor алёрты ACA: restart/CPU/RAM (backend) + restart (frontend) -> ag-mh-central-notify.
