# План: Редизайн Mini App по hi-fi handoff (автономно)

**Handoff:** `docs/design/izigo_handoff/design_handoff_izigo_miniapp/`. Ветка `feat/miniapp-redesign`
(поверх Фазы 3). Мандат: автономно до прода (merge+deploy). Дизайн = визуал, API из Фаз 1–3 сохраняем.

- [ ] **R1 — Токены/тема/шрифт.** `telegram.js`: `antdThemeFromTelegram` + `miniAppPalette` под значения
  handoff (light/dark), fix `fontFamily` (битый Inter → системный). Manrope через `next/font/google`
  (layout.js) → CSS-переменная для цифр/заголовков. `@ant-design/icons` в deps (пин 5.5.1).
- [ ] **R2 — tint-хелперы.** Палитры бейджей (тип/роль/статус) текст/фон по теме + dot-цвета бонусов.
- [ ] **R3 — Экраны (MiniAppShell).** 5 вкладок с иконками (УБРАТЬ отдельную «Кошелёк» → влить в «Доход»:
  hero-сумма + «Доступно к выводу» + кнопка «Вывести» + форма/заявки). Доход / Команда (сводка+фильтр+
  дерево с tint) / Ранг (трофей+степпер+прогресс) / Профиль (шапка+реф-код+настройки). Логика API/handlers
  сохранена. Кастомный таб-бар на иконках.
- [ ] **R4 — Админка.** `AdminWithdrawals` хардкод-цвета → токены; `MiniAppAdmin` под дизайн. Общие вьюхи
  перекрашиваются через ConfigProvider-токены (не ломаем web-кабинет).
- [x] **R5 — Проверки.** `npm run build` exit 0 (Manrope/иконки/новый shell собрались).
- [x] **R6 — Гейт 4.** reviewer → применены P0 (история кошелька + разделение критичных/опциональных
  запросов, чтобы сбой кошелька не ронял кабинет) и P1 (clawback партнёру, ключи дерева, spread) → build.
- [x] **R7 — Деплой В ПРОДЕ.** merge → main + chore/phase-0-foundation (c0f186f) → CI deploy success.
  Ревизии backend-0000013 / frontend-0000010 Healthy/RunningAtMaxScale, бот жив. Smoke: роуты Фазы 3
  → 401, фронт /miniapp → 200. **Фаза 3 + редизайн В ПРОДЕ.**

**⚠️ Operational (требует пользователя):** OIDC federated credential настроен только для ветки
`chore/phase-0-foundation`. Деплой с `main` падает (AADSTS700213). Создание credential для main
заблокировано auto-классификатором (auth-change) — сделать вручную:
`az ad app federated-credential create --id 27457743-c362-4950-98cd-8c1a124d9783 --parameters
'{"name":"izigo-main","issuer":"https://token.actions.githubusercontent.com",
"subject":"repo:bronxtc52/izigo:ref:refs/heads/main","audiences":["api://AzureADTokenExchange"]}'`
Пока деплой-ветка остаётся `chore/phase-0-foundation` (обе ветки на одном коммите).

---

# План: Фаза 3 — Финансовое ядро (ledger + e-wallet + выводы)

**ТЗ:** `docs/specs/2026-06-21-phase3-financial-core.md`. (Гейт 2 — план, без кода.)
**Модель:** реалтайм-зачисление при активации; MVP = ledger + wallet + выводы (ручная выплата).
Предыдущие планы (Telegram-only, Фаза 1ц2+2) — ниже как архив.

## A. Целевая архитектура

### Двойная запись (счета)
Деньги — целые центы (`Domain/ValueObject/Money`), `decimal(20,2)` в БД. Каждая операция = группа
проводок, где `Σ debit = Σ credit`. Счета (`account_type`):
- `company_commission_expense` — расход компании на бонусы (member_id = NULL).
- `member_available` — доступный баланс партнёра (обязательство компании перед ним).
- `member_held` — средства в холде под заявку на вывод.
- `company_payouts_paid` — выплачено наружу (member_id = NULL).
- `member_clawback_debt` — долг партнёра при отрицательной коррекции (clawback), гасится
  будущими начислениями.

Проводки операций:
- **Начисление (дельта +X у узла):** Dr `company_commission_expense` X / Cr `member_available` X.
- **Коррекция (дельта −Y):** Dr `member_available` Y / Cr `company_commission_expense` Y.
  Если `available < Y` — уводим available только до 0, остаток Z в долг:
  Dr `company_commission_expense` Z / Cr `member_clawback_debt` Z (clawback).
- **Заявка на вывод (холд):** Dr `member_available` X / Cr `member_held` X.
- **Отклонение/отмена (возврат холда):** Dr `member_held` X / Cr `member_available` X.
- **Выплачено (paid):** Dr `member_held` X / Cr `company_payouts_paid` X.
- **Гашение долга будущим начислением:** часть новой дельты сначала закрывает `member_clawback_debt`
  (Dr `member_clawback_debt` / Cr `company_commission_expense`), остаток — в `member_available`.

### Хранение
- `ledger_entries` (append-only, иммутабельный): `id`, `tx_id` (группа проводок), `member_id` (nullable),
  `account_type`, `direction` (debit|credit), `amount_cents` (bigint), `source_type`
  (accrual|withdrawal|adjustment), `source_id`, `idempotency_key` (unique nullable), `meta` json,
  `created_at`. Индексы: (member_id, account_type), (source_type, source_id), unique(idempotency_key).
- `member_wallets` (денормализованный кэш баланса, source of truth = ledger): `member_id` unique,
  `available_cents`, `held_cents`, `clawback_debt_cents`, `currency`, `updated_at`. Обновляется в той же
  транзакции, что и проводки. Инвариант: значения = свёртка ledger по типам счетов (проверяется тестом).
- `withdrawal_requests`: `id`, `member_id`, `amount_cents`, `payout_details` (text), `status`
  (requested|approved|paid|rejected|cancelled), `requested_at`, `decided_by` (member_id),
  `decided_at`, `paid_at`, `reject_reason` (nullable), `idempotency_key`.

## B. Интеграция с активацией (ключевая механика дельты)

`ActivationService::recompute()` сейчас: `delete()` snapshot → полный пересчёт сети → перезапись.
Дополняем (в той же `DB::transaction`):
1. **До** удаления снимаем `prev[member_id] = member_earnings.total` по всем узлам.
2. Пересчитываем ядром, получаем `new[member_id]`.
3. Для каждого узла `Δ = new − prev`; если `Δ ≠ 0` — пишем ledger-проводки начисления/коррекции
   (см. §A) с `idempotency_key = "accrual:ae{activationEventId}:m{memberId}"`.
4. Обновляем `member_wallets` на дельту (с учётом clawback-правил).
- Идемпотентность всей активации уже гарантирована (`activation_events.idempotency_key` +
  early-return при повторе) — recompute и проводки выполняются ровно один раз на событие.
- `member_wallets` берём `lockForUpdate` (как `Member` сейчас) — защита гонок.

**Clawback-дефолт (подтверждён):** доступный баланс НЕ уходит в минус; излишек коррекции висит
в `member_clawback_debt` и гасится будущими начислениями. Вывод доступен только из `available ≥ 0`.

## C. API

**Кабинет** (`telegram.auth`, свой `member` из initData):
- `GET  /api/cabinet/wallet` — { available, held, clawback_debt, currency }.
- `GET  /api/cabinet/wallet/transactions?cursor=` — история проводок партнёра (пагинация).
- `POST /api/cabinet/withdrawals` — { amount, payout_details } → создать заявку (холд).
- `GET  /api/cabinet/withdrawals` — мои заявки + статусы.

**Админка** (`telegram.auth` + `calculator.role:owner,finance`):
- `GET  /api/admin/withdrawals?status=` — очередь.
- `POST /api/admin/withdrawals/{id}/approve` — зафиксировать (остаётся в холде до paid).
- `POST /api/admin/withdrawals/{id}/reject` — { reason } → возврат холда.
- `POST /api/admin/withdrawals/{id}/mark-paid` — выплачено вручную.

Валидация выводов: `amount ≤ available`, `amount > 0`, статус-переходы строго по циклу,
idempotency на approve/reject/paid.

## D. Frontend (Next App Router, mobile-first Mini App)

- Кабинет: экран **Кошелёк** (баланс available/held/debt + история операций), **форма вывода**,
  **список заявок** со статусами. Переиспользуем стиль редизайна Mini App.
- Админ-раздел: **очередь выводов** (фильтр по статусу) + действия approve/reject/mark-paid,
  карточка заявки (партнёр, сумма, реквизиты, баланс). Видимость по роли.
- LLM-текста наружу нет → санитайзер не требуется (правило соблюдено по умолчанию).

## E. Пошаговый план (файлы; модуль `Modules/Calculator`)

**Шаг 1 — Фундамент ledger (БД + домен):** ✅ ГОТОВО (тест зелёный, 51 assertion)
- [x] Миграции: `2026_06_21_0101..03_*` ledger_entries / member_wallets / withdrawal_requests.
- [x] Модели: `Models/LedgerEntry`, `Models/MemberWallet`, `Models/WithdrawalRequest`.
- [x] `Services/LedgerService` — `post()` с проверкой `Σdebit=Σcredit`; accrual±/clawback,
  hold, releaseHold, markPaid; обновление `member_wallets` (lockForUpdate) в той же транзакции.
- [x] `Tests/Feature/LedgerServiceTest` — баланс сходится, clawback, гашение долга, холд/возврат/
  выплата, идемпотентность, инвариант «кэш = свёртка ledger».

**Шаг 2 — Привязка к активации:** ✅ ГОТОВО (тест зелёный, регресс активации не сломан)
- [x] `ActivationService::recompute()` снимает prev-доход до delete, считает Δ=new−prev по узлам,
  пишет проводки через `LedgerService::accrual` (+ `decimalToCents`). Inject `LedgerService`.
- [x] `Tests/Feature/AccrualLedgerTest` — referral/binary дельта в кошелёк сходится с earnings;
  повтор не задваивает. Clawback/реверс уже покрыты на уровне `LedgerServiceTest` (Шаг 1).

**Шаг 3 — Кошелёк (read) + кабинет:** ✅ ГОТОВО (backend тест зелёный; UI написан)
- [x] `Services/WalletService` (баланс из кэша + лента движений available, курсорная пагинация).
- [x] `CabinetController::wallet/walletTransactions` + роуты `/cabinet/wallet[/transactions]`.
- [x] Frontend: вкладка «Кошелёк» в `MiniAppShell` (баланс available/held/долг + история).
- [x] `Tests/Feature/WalletCabinetTest` — баланс=ledger, изоляция между партнёрами, 401 вне Telegram.

**Шаг 4 — Заявки на вывод (партнёр):** ✅ ГОТОВО (тест зелёный)
- [x] `WithdrawalService::create()` — парсинг суммы в центы, валидация ≤ available, холд, в транзакции.
- [x] `CabinetController::withdrawals/createWithdrawal` + роуты; Frontend: форма вывода + список заявок.
- [x] `Tests/Feature/WithdrawalCabinetTest` — холд, превышение→404, ноль→404, список, 401.

**Шаг 5 — Approval-флоу (финансист):** ✅ ГОТОВО (тест зелёный)
- [x] `WithdrawalService::approve/reject/markPaid/cancel()` — статус-машина (lockForUpdate),
  возврат холда при reject/cancel, 422 при недопустимом переходе.
- [x] `AdminController` + роуты под `calculator.role:owner,finance`.
- [x] Frontend: `AdminWithdrawals` (очередь + действия) в `MiniAppAdmin` (секция «Выводы»).
- [x] `Tests/Feature/WithdrawalAdminTest` — переходы, возврат холда, 422, RBAC 403.

**Шаг 6 — Гейт 4 (ревью + тесты):** ✅ ГОТОВО
- [x] `reviewer` (read-only) — P0-блокеров нет; инварианты подтверждены. Применены P1+P2:
  баланс партнёра в очереди выводов (ТЗ US-4) + строковый `centsToDecimal` без float (3 сервиса).
- [x] Полный PgSQL-прогон: 82 passed (309 assertions); Фаза 3 — 25 тестов зелёные. Падают только
  legacy `StructureTest` (предсессионный долг `calculator_user_tokens.email`, НЕ наш регресс).
- [x] Frontend `npm run build` — exit 0.
- [ ] Ручной клик-тест в Telegram Mini App — за пользователем.

**Отложено (осознанно, P2 из ревью — на следующую итерацию):**
- `mmWalletTx` не пробрасывает cursor → история кошелька в UI ограничена 50 последними движениями
  (бэкенд-пагинация готова). Для MVP достаточно.
- `withdrawal_requests.idempotency_key` объявлен, но не используется в `create()` — защита от
  дубль-заявки только через lock на баланс (деньги не задваиваются, но возможны две заявки).
- Холд НЕ подлежит clawback (by design): «протухшую» заявку финансист видит по балансу партнёра
  в очереди и решает вручную (ручная выплата). Авто-cancel при clawback — будущая итерация.

## F. Разбивка MVP → далее

- **MVP (эта итерация):** Шаги 1–6 выше.
- **Далее (вне итерации):** commission_run/период-клозинг, мультивалюта+конверсия, платёжные
  шлюзы (Фаза 4), KYC/2FA (Фаза 5), отчёты по выплатам/комиссиям для админки.

## G. Риски / на что смотреть

- **Производительность recompute:** при больших сетях полный пересчёт + дельта на узел дороже.
  MVP оставляет текущую модель полного recompute; оптимизация (точечный пересчёт поддерева) — позже.
- **Согласованность кэша и ledger:** только в одной транзакции; тест-инвариант обязателен.
- **Clawback UX:** партнёр может увидеть «долг» — на UI показать понятно (доступно к выводу = available).
- **Гранулярность проводок:** MVP — агрегированная дельта на узел за активацию (не по типам бонусов);
  разбивку по типам (referral/binary/...) держать в `meta`/снимке, не раздувая ledger.

---

# План: Telegram-only авторизация (схлопывание идентичности в Member)

**ТЗ:** `docs/specs/2026-06-21-telegram-only-auth.md`. (Гейт 2 — план, без кода.)
Предыдущий план (Фаза 1ц2 + Фаза 2) — ниже как архив.

## Целевая архитектура

- **Единственная идентичность платформы — `Member`** (ключ `telegram_id`). Роли — на `Member`
  (`member_roles`). Email-вход и `members.calculator_user_id` удаляются.
- **NB (легаси-витрина):** таблицы `calculator_users`/`calculator_user_tokens`/`calculator_structures`
  и токен-флоу (`SetCalculatorUserMiddleware`/`CheckUserTokenMiddleware`/`/calculator/structure/*`)
  — это персистентность ПУБЛИЧНОГО калькулятора-витрины (анонимный инструмент, не логин платформы).
  Их НЕ трогаем (по ТЗ витрина живёт). `CalculatorUser` как идентичность ПЛАТФОРМЫ устранён
  (нет email-входа, нет роли-линка, нет members.calculator_user_id), но таблица остаётся под витрину.
- **Доступ — по initData на каждый запрос** (HMAC, как в Mini App). Bearer-токена нет.
- **Единая поверхность — Telegram Mini App** (работает и в Telegram Desktop). Кабинет и
  админка живут внутри неё; админ-разделы видны по роли. Браузер вне Telegram → «Откройте
  через Telegram». Никакого email/password/Login Widget.
- **Бутстрап owner:** `OWNER_TELEGRAM_IDS` из env/Key Vault; при первом входе участник с таким
  `telegram_id` получает роль `owner`. Первый владелец — `201374791` (@bronxtc52).

## Целевая схема БД (Postgres, beta-данные сбрасываем → migrate:fresh)

- `members`: + `telegram_id` (bigint, **unique, NOT NULL**), `telegram_username`,
  `first_name`, `last_name`, `language`, `currency`; **убрать** `calculator_user_id`.
  Остальное (sponsor_id/parent_id/position/path ltree/ref_code/package_id/rank_id/status/version) — как есть.
- `member_roles` (member_id, role_id) — вместо `role_user`. Опц. `leader_scope_member_id` на роли
  лидера переносим на `members` (или в пивот).
- **Удаляем** миграции `create_calculator_user`, `add_password_to_calculator_users`,
  `add_telegram_to_members` (складываем в `create_members`), `create_*_tokens`, `role_user`.

## Декомпозиция (вертикально; каждый под-этап → свой Гейт 4)

### A1 — Backend: Member как идентичность (схема + модели)
1. Переписать миграции под целевую схему: `members` (telegram_id NOT NULL unique + профильные поля,
   без calculator_user_id), `member_roles`. Удалить миграции calculator_users/password/add_telegram/tokens/role_user.
2. Модель `Member`: fillable новых полей; `roles()`, `hasAnyRole()`, `isOwner()`, `leaderScopeMemberId()`.
   `Role`: `members()`. Удалить модели `CalculatorUser`, `CalculatorUserToken`.
3. Конфиг `telegram.owner_ids` (env `OWNER_TELEGRAM_IDS`, источник — KV; не в git).

### A2 — Backend: auth по Telegram + единая регистрация
4. Middleware `ResolveTelegramMember` (alias `telegram.auth`): читает `X-Telegram-Init-Data`,
   валидирует HMAC (`TelegramInitData`), резолвит/создаёт `Member`, при первом входе из
   `owner_ids` назначает роль `owner`, кладёт текущего `Member` в request. Заменяет
   `SetCalculatorUserMiddleware`.
5. `MemberService`: единый `registerTelegram` (спонсор из `start_param`, гонка unique→reuse) +
   назначение owner-роли. Удалить email-`register`.
6. Удалить `LocalAuthController`/`LocalAuthService`/маршруты `/auth/register|login`.
   `CalculatorAuthService`/фасад → аксессор текущего `Member` (`CurrentMember`).
7. Маршруты: `/cabinet/*` и `/admin/*` под `telegram.auth`; админ + `RoleMiddleware` (по ролям
   Member). Удалить дублирующие `/miniapp/*` + `MiniAppController` (логика — в middleware и `CabinetController`).

### A3 — Backend: сервисы кабинета/админки на Member
8. `CabinetService::currentMember()` — из request (а не из токена).
9. `AdminService`: список/поиск, leader-scope и assign/revoke роли — по `Member`/`member_roles`.
10. `RoleMiddleware` — по текущему `Member`. Resources/`StructureResource` — без `CalculatorUser`.

### A4 — Frontend: единая Mini-App-поверхность
11. Удалить `views/auth/LocalAuth.js`; убрать web-гейт по `userToken` (`GlobalContext`).
12. API-обёртки → заголовок `X-Telegram-Init-Data` вместо `CalculatorAuthToken` (cabinet/admin/api).
13. Mini App shell содержит кабинет + админ-таб (по роли); standalone-браузерные `/cabinet`,`/admin`
    → редирект/экран «Откройте через Telegram» (как `/miniapp` при пустом initData).
14. Витрину-калькулятор оставить публичной — отвязать от email-токена (проверить
    `CalculatorWrapper/Information/AddNode/utils`).

### A5 — Тесты, чистка данных, деплой
15. Тесты: `LocalAuthTest`→`TelegramAuthTest` (первый вход создаёт Member; owner-бутстрап;
    нет `/auth/*`). Обновить Cabinet/Admin/Placement/Activation/Structure-тесты на initData + Member.
16. Прод (beta): `migrate:fresh --force` (данные сброшены), `OWNER_TELEGRAM_IDS` в KV (201374791).
17. Гейт 4: reviewer → правки → tester → ручной клик-тест в Telegram.

## Чек-лист
- [x] A1 схема+модели  [x] A2 auth+регистрация  [x] A3 сервисы  [x] A4 frontend  [x] A5 тесты
- [x] Гейт 4: reviewer (P0 нет) → правки (удалены орфанные DTO/lang email-слоя) → tester (backend 36/36 зелёные; фронт `npm run build` зелёный).
- Осознанные хвосты: легаси «сохранить структуру» выключено (см. ТЗ §6a); мёртвый web-cabinet/admin
  фронт недостижим (редирект на /miniapp) — удалить отдельным cleanup; `currency` на members не добавлен (не нужен).
- [ ] ДЕПЛОЙ: migrate:fresh на beta + OWNER_TELEGRAM_IDS в KV (201374791) — см. ниже.

## Гейт 4 (на каждый под-этап)
reviewer (корректность, RBAC-гейты по Member, отсутствие альтернативных входов, чистота ядра) →
правки → tester (миграции/тесты/сценарии) → ручной клик-тест в Telegram.

---
---

# [АРХИВ] План: Фаза 1 ц2 + Фаза 2 — реальная сеть + кабинет + админка

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
