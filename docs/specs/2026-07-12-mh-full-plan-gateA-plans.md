# Гейт A — сводные планы блока mh-full-plan

**Дата:** 2026-07-12 · **Источник:** Workflow armada-plan-mh-full (preflight + 16 планировщиков, read-only)
**Роадмап:** 2026-07-12-mh-full-plan-roadmap.yml · **Триаж решений:** 2026-07-12-mh-full-plan-dec-triage.md
**Статус:** ожидает утверждения владельцем (Гейт A). Код не начат.

---

## Preflight — карта рисков

I have a complete picture now. Here is my recon map.

---

# Карта рисков блока mh-full-plan (16 задач)

Стек: Laravel-модуль `Modules\Calculator` (nwidart/laravel-modules), фронт — Next.js. Все структурные факты ниже подтверждены чтением исходников.

## (1) ОБЩИЕ ФАЙЛЫ — ТОЧКИ КОНФЛИКТА ПРИ ПАРАЛЛЕЛЬНОЙ РАБОТЕ

**Горячие файлы (правят почти все задачи → сериализовать через merge-train или маркер-блоки):**

- **`.../Providers/CalculatorServiceProvider.php`** — единственная точка DI-регистрации, команд, расписания. Правят: T02/T03/T04/T06-T10 (новые сервисы V2 в `register()`), T04 (новые джобы в `registerCommands()` строки 133-144 + `registerCommandSchedules()` строки 149-171), T02 (джобы НС→ОС 1/16), T09 (квартальная выплата). Это самый острый конфликт-хотспот блока. Рекомендация: заводить регистрации V2 через ОТДЕЛЬНЫЙ `CalculatorV2ServiceProvider` (регистрируется одной строкой из основного), а расписание — блочными маркерами по образцу Block C.
- **`.../Routes/api.php`** (18.5KB, ~263 строки) — базовый роут-файл. В конце (строки 257-263) паттерн `require __DIR__ . '/api/<feature>.php'`. Каждая V2-фича должна добавлять СВОЙ файл в `Routes/api/` и одну строку `require` — конфликт только на одной строке. Правят: T02, T03, T05-T14.
- **`phpunit.xml`** (в `mh-calc-backend-main/`) — общий, env-переменные (строки с `<env>`). Конфликт при добавлении V2-флагов окружения. Тест-сьюты статичны (`Tests/Unit`, `Tests/Feature`), новые тесты конфликтов не создают.
- **Фронт-навигация — уже решённый паттерн, ПЕРЕИСПОЛЬЗОВАТЬ:**
  - Админка: `src/views/admin/web/nav/registry.js` — массив `blockCSections` с маркерами `>>> Block C sections`. Каждая фича = свой `<feature>.nav.js` + одна строка в массиве. НЕ трогать `WebAdminShell.js`. T13 идёт сюда.
  - Mini App: `src/views/miniapp/tabs/registry.js` — `blockCTabs` с маркерами. Свой `<feature>.tab.js` + строка. НЕ трогать `MiniAppShell.js`. T14 идёт сюда.
- **Фронтовые API-клиенты:**
  - `src/views/admin/api.js` (web-токен, `getData`/`sender` из `@/common/utils/utils`, base `/api/v1/...`) — правят T13.
  - `src/views/miniapp/api.js` (заголовок `X-Telegram-Init-Data`, `Accept-Language`, `req()`) — правят T14. Оба append-only по функциям — конфликт умеренный.

**Миграции** (`.../Database/Migrations/`, 46 шт., автозагрузка `loadMigrationsFrom` в провайдере, строка 53). Порядок по timestamp в имени. Все V2-миграции датировать `2026_07_12_*`+ и НЕ пересекать с V1-таблицами (forbidden zone — только читать структуру). Конфликтов между задачами нет, если каждая берёт свой timestamp-слот; риск — коллизия времён, назначить диапазоны по задачам.

**`RouteServiceProvider.php`** — стабилен, менять НЕ нужно (префикс `api/v1`, middleware `api`). Хорошая новость: не хотспот.

## (2) LEDGER — ТЕКУЩЕЕ УСТРОЙСТВО И ТОЧКИ РАСШИРЕНИЯ ПОД ОС/НС/БС (T02)

**`.../Services/LedgerService.php`** — double-entry, источник истины по деньгам, всё в **integer USD-центах**.
- Счета — строковые константы (строки 25-32): `company_commission_expense`, `company_payouts_paid`, `member_available`, `member_held`, `member_clawback_debt`, `company_deposits`, `company_sales_revenue`.
- Проводки: `post()` (строка 228) пишет группу сбалансированных leg'ов с общим `tx_id` (UUID) в таблицу `ledger_entries`; `assertBalanced()` требует Σdebit=Σcredit.
- **Идемпотентность**: `alreadyPosted($idempotencyKey)` (строка 255) — unique-ключ вешается на ПЕРВУЮ проводку группы (строка 246). Повтор ключа = no-op.
- Денормализованный кэш баланса — `member_wallets` (`available_cents/held_cents/clawback_debt_cents`), обновляется в той же транзакции под `lockWallet()` (row-lock, строка 202). Вывод средств — только `member_available` → `member_held` → `company_payouts_paid`.
- Вызывается ВНУТРИ внешней `DB::transaction` (активация/выводы).

**Точки расширения под ОС/НС/БС:** субсчета добавляются как НОВЫЕ константы счетов + новые методы проводок рядом (не переписывая `accrual/hold/charge`). Механизм `post()/leg()/assertBalanced()/alreadyPosted()` — универсальный, переиспользуется. НО: `member_wallets` имеет фиксированные колонки `available/held/clawback` — для ОС/НС/БС и кредит-лотов (FIFO, срок 1 год) нужна НОВАЯ таблица(ы) лотов и/или расширение wallet-кэша. Ограничение «оплата с ОС ≤70%» и «вывод только с ОС» — логика поверх ledger, не в нём. `charge()` (строка 177) сейчас списывает только с `member_available` — T02 добавляет параллельный путь списания с субсчёта ОС.

## (3) ЗАКАЗ / ОПЛАТА / АКТИВАЦИЯ — ГДЕ СНАПШОТИТЬ BV/PV (T03)

Цепочка: `PaymentService` → `OrderService::markPaid` → `ActivationService::activate` → `recompute` (V1 Domain).

- **`OrderService.php`**: заказ создаётся с `total_usdt_cents` (BV-деньги) и `total_pv` (строки 60-61, 113-114); `OrderItem` снапшотит `unit_price_usdt_cents`, `pv`, `name_snapshot` (строки 65-72). **Это и есть текущий снапшот на заказ** — точка для DEC-003 (раздельные BV/PV снапшоты). `markPaid()` (строка 140) идемпотентно переводит `pending_payment→paid`, промоутит лида в Member, вызывает `activation->activate(memberId, packageId, "order:{id}", displayName)` (строка 196).
- **`PaymentService::applyPaid`** (строка 474, ВНУТРИ `DB::transaction` из webhook/poll) — единственная точка фиксации оплаты: `PURPOSE_ORDER → orders->markPaid()`; `PURPOSE_TOPUP → ledger->deposit()`. Это точка входа для активации счетов V2.
- **`ActivationService.php`** (форбидден-адъяцентный: сам не в Domain, но вызывает V1 `CompensationEngine`): `activate()` под транзакционным advisory-lock `ACTIVATION_LOCK_KEY=0x12916001` (строка 32) — глобально сериализует пересчёты. `recompute()` (строка 155) делает **полный delete/rewrite** снапшота `member_bonus_lines`+`member_earnings` и пишет ДЕЛЬТА-проводки в ledger (строки 201-213). **V2-движок должен встать здесь ПАРАЛЛЕЛЬНО** (feature-flag выбор V1/V2 в этой точке или в `applyPaid`), НЕ трогая `CompensationEngine`. BV/PV-снапшот для бинарных PV-лотов (T03, DEC-055) снимать на моменте `markPaid` из `OrderItem`.

## (4) СУЩЕСТВУЮЩИЕ ПАТТЕРНЫ (переиспользовать во всём блоке)

- **Роуты фичами**: отдельный файл в `Routes/api/<feature>.php` + `require` в `api.php`. Внутри — фасад `Route`, префикс `api/v1` наследуется. Группы `cabinet` (middleware `telegram.auth`) и `admin` (middleware `web.admin` + `calculator.role:owner`). Образец — `Routes/api/feature_flags.php`.
- **Feature-flags**: middleware `feature.flag:{alias}` (алиас в провайдере строка 66) на группе роутов; сервис `FeatureFlagService::isEnabled()` (deny-by-default, кэш 60с, источник — таблица `feature_flags`). НЕТ реестра известных флагов — `set()` создаёт запись при первом тоггле; V2 флаги заводить миграцией-сидом. Фронт скрывает табы/секции по карте флагов (`visibleBlockCSections`/`visibleBlockCTabs`), но это UX — реальный гейт на бэке. **Это готовый механизм для V1→V2 cutover (T15).**
- **Фронт → API**: admin через `getData/sender` + web-токен (`src/views/admin/api.js`); miniapp через `req()` + `X-Telegram-Init-Data` (`src/views/miniapp/api.js`). Base URL `API_SERVER_URL` из `@/common/utils/utils`.
- **Локали RU/EN**: фронт — `src/common/i18n.js` + `src/locales`; miniapp нормализует язык до ru/en и шлёт `Accept-Language`. Бэк-переводы — `Resources/lang/` (есть `ru`, нет `en`-каталога — присутствуют az/kk/ky/mn/ru/uz; для T14 RU/EN проверить наличие en-строк) + `translation_overrides` (C4 i18n). T14 локализацию вести через существующий i18n.
- **Джобы**: НЕ Laravel queue, а планировщик (`registerCommandSchedules`), команды в `Console/`, идемпотентность + `withoutOverlapping`. Образец для T04.
- **Деньги integer-центы, идемпотентные ключи** — сквозной паттерн (ledger, activation, payments).
- **Config**: `Config/config.php` (env-driven, секреты из Key Vault). План-конфиг сейчас — `PlanSettingsService` поверх `plan_settings` таблицы + `IziGoPlanFactory::mergedConfig` (дефолты+оверрайды), forward-only. T01 (версионируемый `PolicyVersion` с valid_from/valid_to) строится РЯДОМ с этим, не заменяя его до cutover.

## (5) ИЗОЛЯЦИЯ ЗАПРЕТНЫХ ЗОН — ПОДТВЕРЖДЕНО

- **`Modules/Calculator/Domain/`** (V1-ядро: `CompensationEngine.php`, `Bonus/`, `Plan/`, `Rank/`, `Model/`, `Repository/`, `Result/`, `ValueObject/`) — только чтение. Вызывается через `ActivationService::recompute` → `new CompensationEngine($plan)->calculate($network)`. V2 встаёт параллельно этому вызову. НЕ входит в Domain: `ActivationService`, `LedgerService`, `OrderService`, `PaymentService` — их можно расширять/оборачивать.
- **`Services/Payment/`** (TON Pay приём) — не переписывать; интеграция через снапшот BV на заказ. `PaymentService.php` (не в `Payment/`) — оркестратор, его точка `applyPaid` доступна для расширения.
- **`.github/workflows/deploy.yml`** — не трогать (в репозитории `.github/` присутствует).

## (6) РЕКОМЕНДАЦИЯ ПО НЕЙМСПЕЙСУ V2 И ТОЧКЕ ПЕРЕКЛЮЧЕНИЯ

- **Неймспейс**: `Modules\Calculator\DomainV2\` (ядро расчёта V2, зеркало `Domain/`) + `Modules\Calculator\ServicesV2\` (LedgerV2/AccountsService, PolicyVersion, периоды, бонусы). Отдельный **`CalculatorV2ServiceProvider`** для DI/команд/расписания V2, подключаемый ОДНОЙ строкой из `CalculatorServiceProvider::register()` — минимизирует конфликты в горячем провайдере.
- **Точка переключения V1→V2**: единый feature-flag (напр. `mh_plan_v2`) через существующий `FeatureFlagService`, проверяемый в ОДНОЙ точке — внутри `ActivationService` (перед `recompute`) или в `PaymentService::applyPaid`. Пока flag OFF — работает `CompensationEngine` V1; ON — V2-движок. Паритетный прогон V1 vs V2 (T15) можно делать shadow-режимом (оба считают, V2 только логирует расхождения) до PROD-гейта. Advisory-lock `ACTIVATION_LOCK_KEY` переиспользовать, чтобы V1 и V2 не задваивали проводки.
- **Порядок расчёта бонусов** критичен и уже зафиксирован (DEC-053): raw → индивид. капы → 60%-пул → база лидерского → posting — это влияет на межзадачные контракты T06→T08→T11.

**Топ-риски для планировщиков:** (1) `CalculatorServiceProvider` — все задачи с сервисами/джобами; изолировать через V2-провайдер. (2) Порядок/timestamp миграций — назначить слоты. (3) Контракт снапшота BV/PV (T03) — зависимость T05/T06/T07; фиксировать формат снапшота на `OrderItem` рано. (4) `member_wallets` схема жёсткая — T02 нужна новая таблица лотов, не расширение. (5) RU/EN на бэке: en-каталог локали отсутствует — T14 учесть.

---

## T01 — Версионируемый конфиг политики V2 (PolicyVersion, полный план MH в USD-центах)
Зависит от: нет

**Таблицы:**
- v2_policy_versions: id; code varchar UNIQUE (напр. mh-v2-usd-1); status enum(draft|active|retired); schema_version smallint; valid_from datetime NULL (ставится при активации, включительно); valid_to datetime NULL (исключительно; NULL = текущая); config JSON (полный документ плана); config_hash char(64) sha256(canonical json) — для снапшотов расчётов T02-T12; notes text NULL; created_by/activated_by bigint NULL; activated_at datetime NULL; timestamps; index (status, valid_from); инвариант «максимум одна active с valid_to IS NULL» и непересечение [valid_from, valid_to) — enforced в сервисе транзакционно под lockForUpdate (MySQL, exclusion-констрейнтов нет)
- Структура config JSON (контракт для T02-T12, все деньги int USD-центы, все ставки int basis points, PV int): meta{currency:USD, kzt_rate:468, timezone:UTC}; tiers[START|BUSINESS|ELITE]{min_pv:100/200/600, max_pv_exclusive:200/600/null, referral_rates_bp:{l1:1000, l2:0/500/800}}; referral{max_depth:2, stop_at_elite:true, destination:OS, trigger:ORDER_PAID}; statuses[12: CLIENT..VICE_PRESIDENT]{ordinal 0-11, qualification{personal_purchase_pv_min:100 (CLIENT), qualified_referrals_min+referral_pv_min:100 (CONSULTANT), small_branch_pv_min:1000/3000/8000/20000/60000/150000/380000/760000/1500000/3000000, direct_referrals_min:4/8 (Manager/Bronze), variants{anchor_rank, support_rank, V1{anchor_count:2, at_least:true(DEC-022)}, V2{anchor:1, support:4, distinct_root_branches:true}, V3{support:8, distinct_root_branches:true}} (Silver+)}, binary_rate_bp:500/500/500/500/600/600/700/700/800/800/900 (CONSULTANT..VP), monthly_cap_cents:50000_00..4000000_00 (USD 500/1000/1500/2000/5000/10000/15000/25000/30000/35000/40000), half_month_cap_cents = monthly/2 (явно, валидатор сверяет, DEC-017), leadership{START:{depth:1,rates_bp:[1000]}, BUSINESS:{depth:1,rates_bp:[1500]}, ELITE:{depth_by_status:0..7, rates_bp:[2000,1000,500,300,100,100,100]}, base:PAID_AFTER_CAPS_AND_POOL (DEC-029), blocking:ordinal>=receiver+3 (DEC-030)}, global_pool{rate_bp:100/75/50/50/25 (Director..VP), one_share_pv_min, max_shares:2 (DEC-032), member_cap_bp:2500, remainder:COMPANY_UNALLOCATED (DEC-034), accrual:MONTH, payout:QUARTER}, award{amount_cents: 100/200/300/500/1500/2500/20000/35000/53000 USD, VP tranches 3x50000, destination:BS, on_rank_jump:ALL_CROSSED (DEC-040)}}; grace{client_to_consultant_days:30}; accounts{OS{withdrawable:true, max_order_payment_share_bp:7000, lot_expiry:P1Y, on_expiry:TRANSFER_TO_BS}, NS{transfer_days:[1,16], transfer_to:OS}, BS{withdrawable:false, purchasable:true, lot_expiry:P1Y, on_expiry:FORFEIT}, lot_consumption:EARLIEST_EXPIRY_FIRST (DEC-015)}; calibration{rate_bp:6000, mode:SCALE_DOWN_ONLY, base:PERIOD_BV, include:ALL_BONUSES (DEC-014)}; rank_forever:true (DEC-020); subscription:absent (DEC-004); mh_discount:absent

**Миграции (по порядку):**
- 2026_07_12_010000_create_v2_policy_versions_table.php — таблица v2_policy_versions (слот T01 = 2026_07_12_0100xx по карте рисков)
- 2026_07_12_010100_seed_v2_policy_default_draft.php — вставка версии-DRAFT mh-v2-usd-1 с каноническим конфигом из ServicesV2/DefaultPolicyConfig::doc() (НЕ активирует; активация — руками owner через админ-endpoint или в T15 cutover). Паттерн сида в миграции уже есть (plan_settings).

**Backend:**
- mh-calc-backend-main/Modules/Calculator/Models/PolicyVersion.php — Eloquent-модель v2_policy_versions, cast config json
- mh-calc-backend-main/Modules/Calculator/DomainV2/Policy/PolicyV2.php — корневой immutable read-model конфига; versionId(), configHash(), типизированные аксессоры (все деньги int-центы, ставки int bp)
- mh-calc-backend-main/Modules/Calculator/DomainV2/Policy/StatusRule.php, TierRule.php, QualificationVariantRule.php, LeadershipRule.php, GlobalPoolRule.php, AwardRule.php, AccountRules.php, CalibrationRule.php — value objects по секциям конфига
- mh-calc-backend-main/Modules/Calculator/DomainV2/Policy/StatusCode.php — enum 12 статусов CLIENT..VICE_PRESIDENT с ordinal
- mh-calc-backend-main/Modules/Calculator/DomainV2/Policy/PolicyResolver.php — ИНТЕРФЕЙС-контракт для T02-T12: resolveForDate(CarbonInterface $at): PolicyV2, бросает PolicyNotActiveException; версия выбирается по дате события (paid_at / границе периода), полуинтервал [valid_from, valid_to)
- mh-calc-backend-main/Modules/Calculator/DomainV2/Policy/PolicyV2Factory.php — array config -> PolicyV2 (строгий парс, unknown keys = ошибка)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/PolicyVersionService.php — implements PolicyResolver; createDraft/updateDraft (только draft мутабелен)/activate/retire/resolveForDate; activate: DB::transaction + lockForUpdate всех строк, валидация конфига, valid_from>=now (retro только с explicit-флагом для T15), автозакрытие предыдущей active (valid_to = new valid_from), проверка непересечения, audit через существующий AuditLogService; per-request кэш resolve
- mh-calc-backend-main/Modules/Calculator/ServicesV2/PolicyConfigValidator.php — по образцу PlanSettingsService::validate: 12 статусов в каноническом порядке, монотонность small_branch_pv, ставки bp 0..10000, капы int центы >=0 с верхними границами, half_month == monthly/2, тиры смежные без дыр/пересечений, сумма global pool rates == 300 bp, длина leadership rates == max depth 7, ссылки anchor/support_rank на существующие статусы, награды int >=0, calibration<=10000; InvalidArgumentException -> 422
- mh-calc-backend-main/Modules/Calculator/ServicesV2/DefaultPolicyConfig.php — канонический PHP-массив конфига (единственный источник: сид-миграция + golden-тесты); без YAML-зависимости, 07_Rules_Config.example.yaml — только референс
- mh-calc-backend-main/Modules/Calculator/ServicesV2/PolicyNotActiveException.php
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — НОВЫЙ провайдер V2 (рекомендация карты рисков): singleton PolicyVersionService, bind PolicyResolver->PolicyVersionService; T02/T04/T09 позже дописывают сюда свои сервисы/команды, НЕ в основной провайдер
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2/PolicyVersionAdminController.php — index (список версий), show (конфиг+hash), storeDraft, updateDraft, activate, retire, resolve?at= (отладка: какая версия действует на дату)
- mh-calc-backend-main/Modules/Calculator/Routes/api/v2_policy.php — admin-группа middleware web.admin + calculator.role:owner (по образцу feature_flags.php); cabinet-роутов нет — конфиг политики партнёру не отдаём в T01 (Mini App-представление — T14)

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php — ровно одна строка $this->app->register(CalculatorV2ServiceProvider::class) в register()
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require __DIR__.'/api/v2_policy.php' в хвостовом блоке
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — T01 создаёт файл, T02/T04/T06-T10 будут дописывать (общий файл ВНУТРИ блока V2)

**Риски конфликтов:**
- CalculatorServiceProvider.php и Routes/api.php: T02/T03/T04+ добавляют строки в те же места — конфликт однострочный, снимается merge-train; T01 должен мерджиться ПЕРВЫМ (он бутстрапит неймспейсы DomainV2/ServicesV2 и V2-провайдер, остальные только дописывают)
- Схема config JSON — межзадачный КОНТРАКТ: ключи потребляют T05 (statuses/tiers/grace), T06 (binary_rate_bp/caps), T07 (tiers.referral_rates_bp/stop_at_elite), T08 (leadership), T09 (global_pool), T10 (award), T11 (calibration), T02 (accounts), T04 (meta.timezone/transfer_days). Переименование ключей после старта волны = каскад по 9 задачам — заморозить схему на Гейте A
- PolicyResolver/PolicyVersion — имена классов, которые соседние задачи могут захотеть завести сами; зафиксировать: владелец типов — T01, все остальные только инжектят PolicyResolver
- Слоты timestamp миграций: T01 занимает 2026_07_12_0100xx; коллизия при самовольном выборе слота соседями
- config_hash/policy_version_id — поля, которые T04 (снапшоты периодов) и T06-T11 (breakdown расчётов) обязаны писать в свои снапшоты; если T01 не отдаст их через PolicyV2, задачи волны 2 заблокируются
- V1 plan_settings/PlanSettingsService не трогаем — риск, что кто-то начнёт читать конфиг V2 из V1-фабрики; правило: V2-код читает ТОЛЬКО через PolicyResolver

**Тест-план:**
- Golden-тест сидируемого конфига (деньги — обязательный): DefaultPolicyConfig проходит валидатор; точные значения в центах по курсу 468 — капы 50000/100000/150000/200000/500000/1000000/1500000/2500000/3000000/3500000/4000000 центов (USD 500..40000), награды 10000/20000/30000/50000/150000/250000/2000000/3500000/5300000 центов + VP 3x5000000, пороги малой ветки 1000..3000000 PV, ставки 500..900 bp, пулы 100+75+50+50+25=300 bp, калибровка 6000 bp, referral 1000 bp L1 и 0/500/800 bp L2, max_shares=2, OS share 7000 bp
- Unit валидатора — negative-cases: пересекающиеся/дырявые тиры; немонотонные пороги статусов; ставка >10000 bp; отрицательный/нецелый кап; half_month != monthly/2; сумма пулов != 300 bp; отсутствующий/лишний статус; неверный порядок ordinal; leadership rates длиной != 7 у ELITE; ссылка anchor_rank на несуществующий статус; unknown top-level key
- Unit resolveForDate: граница valid_from включительно, valid_to исключительно; две последовательные версии — дата попадает в правильную; нет active на дату -> PolicyNotActiveException; дата до первой активации -> exception
- Feature активации: activate закрывает предыдущую active (valid_to=new valid_from); повторная активация пересекающегося интервала -> 422; активация уже retired/active версии -> 409/422; updateDraft на active -> 422 (immutability); valid_from в прошлом без retro-флага -> 422; config_hash пересчитан и стабилен (canonical json)
- Concurrency: две параллельные активации под транзакциями -> ровно одна active (lockForUpdate), вторая получает ошибку — critical, деньги зависят от единственности версии
- Права negative: admin-роуты без токена -> 401; web-admin не-owner -> 403; cabinet telegram-токен на admin-роут -> 401/403
- Audit: create/activate/retire пишут AuditLogService-записи before/after
- Регресс V1: миграции T01 не трогают plan_settings; существующие тесты V1-плана зелёные

**Вопросы к Гейту A:**
- Тиры vs текущие тарифы IziGo: спека/роадмап задают тиры по personal PV 100-199/200-599/600+, но действующие тарифы IziGo — Bronze 90 / Silver 180 / Gold 540 PV (DEC-010 триажа), то есть покупатель Bronze не дотягивает даже до START. Что правим: PV тарифов до 100/200/600 или сидируем пороги тиров 90/180/540? (влияет на сид T01 и на T05/T07)
- Подтверждение значения флага referral_stop_at_elite в сиде: роадмап T07 выносит вкл/выкл на Гейт A; T01 сидирует дефолт TRUE (покупки ELITE-покупателя не генерят реферальную) — оставить TRUE?

**Допущения:**
- MySQL в проде (sqlite в тестах) -> непересечение интервалов и единственность active enforce'ится сервисом в транзакции под lockForUpdate, DB-констрейнта на интервалы нет
- Статусная модель версии упрощена до draft/active/retired: единственный owner-админ, четырёхглазого approve нет (в духе IZIGO_CONTEXT DEC-005/043), компенсация — audit-лог; отдельный статус APPROVED не заводим
- Версия применяется по дате события: заказ — по paid_at, периодные расчёты — по границе периода (спека 05 §5.1 шаг 3); полуинтервал [valid_from, valid_to)
- Активация новой версии автоматически закрывает предыдущую (valid_to = valid_from новой); retro-активация запрещена, кроме явного флага для cutover T15
- Все проценты храним integer basis points (bp), деньги — integer USD-центы, PV-пороги — integer: float в money-контуре не появляется; округление — на финальной проводке каждого бонуса (DEC-002), это зона T06-T11, не T01
- Полумесячный кап хранится явным полем и валидируется как monthly/2 (DEC-017) — потребителям (T06) не нужно делить самим
- Решения владельца вшиты в сид: без годовой подписки (DEC-004), без скидки MH, ранг навсегда без maintenance-полей (DEC-020), награды деньгами USD на БС с выплатой вручную, 60% полная калибровка scale-down-only (DEC-014), max долей глобального = 2 (DEC-032), все пройденные награды при скачке (DEC-040), курс 468 KZT=1 USD зафиксирован в meta конфига
- Timezone расчётных границ — UTC (по роадмапу T04, а не Asia/Almaty из спеки); хранится в meta.timezone конфига, чтобы T04 читал из политики
- YAML-зависимость не добавляется: канонический конфиг — PHP-массив DefaultPolicyConfig, 07_Rules_Config.example.yaml остаётся референсом
- V1 (plan_settings/PlanSettingsService/IziGoPlanFactory) не изменяется и работает до cutover T15; фронтовых работ в T01 нет (админ-UI политики — T13, Mini App — T14)
- Кабинетных (партнёрских) endpoint'ов для политики в T01 нет — конфиг доступен только owner-админу; feature-flag на admin-роуты не вешаем (гейт owner-роли достаточен), общий флаг cutover mh_plan_v2 — зона T15

## T02 — Счета ОС/НС/БС поверх ledger: субсчета V2, кредит-лоты 1 год (earliest-expiry-first), НС→ОС 1/16, оплата заказа с ОС ≤70%, совместимость с V1-балансом и выводом
Зависит от: T01 — PolicyVersionResolver: контракт `configFor(CarbonInterface $date): array` с ключами accounts.* в USD-центах/днях: os_order_payment_max_percent (70), os_lot_lifetime_days (365), bs_lot_lifetime_days (365), ns_transfer_days ([1,16]); T02 читает конфиг только через этот резолвер, дефолты дублирует в коде fail-safe, T01 — Providers/CalculatorV2ServiceProvider.php: если T01 его создаёт (рекомендация preflight), T02 только дополняет регистрации; если нет — T02 создаёт его сам и добавляет 1 строку в CalculatorServiceProvider::register()

**Таблицы:**
- member_accounts_v2 — денормализованный кэш субсчетов (rebuildable projection ledger_entries): member_id FK unique, os_available_cents, os_held_cents, ns_cents, bs_available_cents, bs_held_cents (все unsignedBigInteger, default 0), currency('USD'), updated_at; инвариант: каждая колонка = Σcredit−Σdebit соответствующего account_type в ledger_entries
- wallet_lots_v2 — кредит-лоты ОС/БС (lot-level expiry, DEC-015): id, member_id FK, account enum('os','bs'), amount_cents (исходная сумма), available_cents (остаток), earned_at, expires_at, source_type(32)/source_id (тип бонуса/награды из T06-T10), origin_lot_id nullable self-FK (связь БС-лота с истёкшим ОС-лотом), status enum('active','exhausted','transferred','expired'), idempotency_key unique nullable, timestamps; индексы (member_id, account, status, expires_at) и (expires_at, status)
- wallet_lot_consumptions_v2 — трассировка списаний (DEC-015 full traceability): id, lot_id FK, amount_cents, reason(24): order_reserve|order_capture|reserve_release|withdrawal_hold|expiry_transfer|expiry_annul|reversal, tx_id uuid nullable (группа проводок ledger), reservation_id nullable, withdrawal_request_id nullable, created_at; индексы lot_id, (reason, reservation_id)
- wallet_reservations_v2 — резерв счетов под заказ (спека 06 §wallet-reservations, ограничение 70%): id, order_id FK, member_id FK, os_cents, bs_cents, status enum('reserved','captured','released','expired'), expires_at nullable, idempotency_key unique, timestamps; partial-unique индекс «один живой (reserved) резерв на заказ» по образцу 2026_07_02 one_live_pending_payment_per_order
- ledger_entries — БЕЗ изменений схемы (account_type string(32) вмещает новые значения): новые типы счетов member_os_available, member_os_held, member_ns, member_bs_available, member_bs_held, системный company_expired_balance; новые source_type: bonus_v2, ns_transfer, lot_expiry, acct_charge, acct_reserve (≤16 симв.)
- feature_flags — сид-запись mh_v2_accounts (enabled=false), гейт всех V2-роутов и джобов T02

**Миграции (по порядку):**
- 2026_07_12_020100_create_member_accounts_v2_table.php
- 2026_07_12_020200_create_wallet_lots_v2_table.php
- 2026_07_12_020300_create_wallet_lot_consumptions_v2_table.php
- 2026_07_12_020400_create_wallet_reservations_v2_table.php
- 2026_07_12_020500_seed_feature_flag_mh_v2_accounts.php (insertOrIgnore в feature_flags, enabled=false)
- Слот timestamp задачи: 2026_07_12_0201xx–0205xx (диапазон 02xxxx закреплён за T02, не пересекается с T01=01xxxx, T03=03xxxx и т.д.)

**Backend:**
- ServicesV2/Ledger/LedgerPostingV2Service.php — НОВЫЙ низкоуровневый постер поверх ТОЙ ЖЕ таблицы ledger_entries, копия механики V1 LedgerService::post()/leg()/assertBalanced()/alreadyPosted() (строки 228-285), но с публичным API и без привязки к member_wallets; V1 LedgerService НЕ трогаем (его приватный saveWallet жёстко связан с V1-кэшем)
- ServicesV2/Wallet/WalletAccountsV2Service.php — ядро T02: credit(memberId, account os|ns|bs, cents, sourceType, sourceId, idemKey, ?earnedAt) — для ОС/БС создаёт лот с expires_at=earned_at+lifetime из PolicyVersion, для НС лот не создаётся; transferNsToOs(memberId, windowDate) — Dr member_ns/Cr member_os_available + новый ОС-лот (earned_at=дата перевода, годовой срок с даты зачисления на ОС по BR-ACC-001), idempotency key v2:ns_transfer:{member}:{Y-m-d} (DEC-019: ключ на окно, без holiday shift); expireLots(asOf) — earliest-expiry-first: истёкший остаток ОС-лота → проводка Dr member_os_available/Cr member_bs_available + новый БС-лот c origin_lot_id; истёкший БС-лот → Dr member_bs_available/Cr company_expired_balance, status=expired; consume(memberId, account, cents, reason,…) — списание по лотам earliest-expiry-first с записями в wallet_lot_consumptions_v2; holdForWithdrawal/releaseWithdrawalHold/markWithdrawalPaid — зеркало V1-цикла вывода, ТОЛЬКО с ОС (os_available→os_held→company_payouts_paid); все методы идемпотентны и обновляют member_accounts_v2 под lockForUpdate по образцу V1 lockWallet (insertOrIgnore+повторная выборка)
- ServicesV2/Wallet/OrderAccountPaymentService.php — резервы под заказ: reserve(order, osCents, bsCents) с валидацией osCents ≤ intdiv(order.total_usdt_cents*70,100) (иначе OsOrderLimitExceededException) и достаточности лотов (атомарный резерв лотов: consumption reason=order_reserve + перевод в *_held-счета); capture(orderId) — Dr held/Cr company_sales_revenue, consumption→order_capture; release(orderId) — компенсирующие проводки и возврат остатков в лоты; remainderCents(order) — сколько осталось оплатить TON; кейс полной оплаты со счетов (ОС≤70% + БС остальное) → markPaid без TON-инвойса
- ServicesV2/Wallet/Exceptions/{OsOrderLimitExceededException,InsufficientAccountBalanceException,ReservationConflictException}.php
- Models/V2/{MemberAccountV2,WalletLotV2,WalletLotConsumptionV2,WalletReservationV2}.php — Eloquent-модели новых таблиц
- Console/V2/NsToOsTransferCommand.php (mh2:ns-transfer) — cron('15 0 1,16 * *') UTC, withoutOverlapping, идемпотентен по окну; Console/V2/WalletLotsExpireCommand.php (mh2:lots-expire) — dailyAt 00:20, withoutOverlapping; оба no-op при выключенном mh_v2_accounts; регистрация ТОЛЬКО в CalculatorV2ServiceProvider
- Providers/CalculatorV2ServiceProvider.php — singletons LedgerPostingV2Service/WalletAccountsV2Service/OrderAccountPaymentService + команды + расписание V2 (создаётся T01 или здесь — см. depends_on)
- Http/Controllers/V2/AccountsV2Controller.php — cabinet: GET accounts (балансы ОС/НС/БС + ближайшие сгорания), GET accounts/lots, GET accounts/history (проводки по V2-типам, пагинация по образцу WalletService::transactions), POST orders/{id}/account-payment (резерв {os_cents,bs_cents}, ответ с remainder), DELETE orders/{id}/account-payment; admin: GET admin/v2/members/{id}/accounts и /lots (read-only минимум для T13)
- Routes/api/accounts_v2.php — свой файл фичи: группы cabinet (middleware telegram.auth) и admin (web.admin + calculator.role:owner), вся фича под feature.flag:mh_v2_accounts; + одна строка require в Routes/api.php
- Services/OrderService.php — ПРАВКА в markPaid() (строки ~140-200): flag-guarded хук «capture живого резерва» перед активацией; append-only блок с маркером T02
- Services/PaymentService.php — ПРАВКА в create()/createForLead(): сумма инвойса = remainderCents(order) вместо полного total при живом резерве (flag-guarded); Services/Payment/ (TON-шлюз) НЕ трогаем
- Services/WithdrawalService.php — НЕ правим в T02: методы hold/release/paid V2 готовы как контракт, переключение вывода на ОС делает T15 по feature-flag

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php — +1 строка регистрации CalculatorV2ServiceProvider (только если её не добавил T01); больше НИЧЕГО в этом файле
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — общий для T01/T02/T04/T06-T10: маркер-блоки '>>> T02 accounts' по образцу Block C
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — +1 строка require accounts_v2.php в хвосте (та же зона, что правят T03/T05-T14)
- mh-calc-backend-main/Modules/Calculator/Services/OrderService.php — markPaid-хук; сюда же лезет T03 (снапшот BV/PV) и T12 (возвраты)
- mh-calc-backend-main/Modules/Calculator/Services/PaymentService.php — сумма инвойса-остатка; сюда же смотрят T03 (applyPaid как точка снапшота) и T15 (точка переключения V1/V2)
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — коллизии timestamp; T02 занимает слот 2026_07_12_02xxxx

**Риски конфликтов:**
- T04 (периоды/джобы): двойное владение переводом НС→ОС 1/16. Контракт: T02 владеет ОПЕРАЦИЕЙ transferNsToOs (идемпотентной по окну) и базовой cron-командой; T04 при появлении периодов перевешивает триггер на «после finalized H-close» (спека 06, job accumulation-to-main-transfer), вызывая тот же метод. Команду T02 писать так, чтобы T04 менял только триггер, не операцию
- T06-T10 (бонусы): все постят деньги через WalletAccountsV2Service::credit(...) — сигнатуру и семантику idempotency key заморозить в первый день T02 и объявить в плане блока; поздние изменения сигнатуры каскадят на 5 задач
- T01: гонка за создание CalculatorV2ServiceProvider и за строку в CalculatorServiceProvider::register(); согласовать — создаёт T01, T02 мерджится после него (merge-train)
- T03: правит OrderService (снапшот BV/PV на markPaid) рядом с хуком T02 — маркер-блоки и порядок: снапшот T03 до активации, capture T02 до/независимо от снапшота; договориться о порядке строк в markPaid
- T12 (возвраты/сторно): будет реверсить capture/лоты/consumption — схема wallet_lot_consumptions_v2 (tx_id + reason=reversal) закладывается сейчас, менять её в T12 нельзя, только использовать
- T15 (cutover): маппинг member_available → ОС и переключение WithdrawalService на V2-методы; T02 обязан оставить публичные hold/release/paid с сигнатурами, совместимыми с вызовами из WithdrawalService (принимают WithdrawalRequest)
- T13/T14: читают admin/cabinet endpoints T02 — формат ответа accounts/lots (центы integer + строковые decimal по образцу WalletService::centsToDecimal) зафиксировать в этом цикле
- Routes/api.php хвост — тривиальный одно-строчный конфликт со всеми задачами блока; разрешается merge-train

**Тест-план:**
- ДЕНЬГИ (обязательные, Unit): LedgerPostingV2Service — Σdebit=Σcredit (unbalanced → DomainException), идемпотентность по ключу (повтор = no-op, проводки не задваиваются), leg amount > 0
- Инвариант кэша: после произвольной последовательности операций member_accounts_v2 == свёртка ledger_entries по V2 account_type (rebuild-тест по образцу V1-инварианта member_wallets)
- Лоты: credit на ОС/БС создаёт лот с верным expires_at из PolicyVersion; списание строго earliest-expiry-first (3 лота с разными expires_at → порядок потребления); частичное списание оставляет available_cents; consumption-строки полны и сходятся с суммой операции
- Expiry job: идемпотентен (двойной прогон — одна проводка); ОС-лот с остатком → БС-лот с origin_lot_id и новой датой сгорания + сбалансированная проводка; БС-лот → company_expired_balance, status=expired; лот, потреблённый в день сгорания, не задваивается
- НС→ОС: перевод 1-го и 16-го идемпотентен по окну (повторный запуск команды в тот же день — no-op); создан ОС-лот со сроком от даты перевода; НС=0 после перевода; при пустом НС — no-op без проводок
- 70%-лимит (negative): резерв os_cents = intdiv(total*70,100) проходит; +1 цент → OsOrderLimitExceededException; резерв БС сверх остатка → InsufficientAccountBalanceException; второй живой резерв на заказ → ReservationConflictException (unique-индекс)
- Резерв: reserve→capture (деньги ушли в sales_revenue, лоты consumed), reserve→release (полный возврат в лоты и available, компенсирующие проводки), capture идемпотентен; полная оплата со счетов (ОС 70% + БС 30%) → markPaid без TON-инвойса → V1-активация выполнилась
- Вывод: hold/release/paid только с ОС; попытка hold больше os_available → отказ; НС и БС невыводимы (negative); БС нельзя использовать в выводе даже при пустом ОС
- Feature (права/флаг, negative): все роуты accounts_v2 при mh_v2_accounts=off → 404/403; cabinet без валидного X-Telegram-Init-Data → 401; admin-роуты без роли owner → 403; партнёр не видит чужие счета/лоты
- Integration (Feature): заказ с частичным резервом — инвойс TON выставлен на remainder, applyPaid → markPaid → capture → активация V1 не сломана; V1-кошелёк (member_wallets) не изменился ни в одном V2-сценарии (регресс совместимости)
- Concurrency: два параллельных reserve на один заказ → ровно один успешен (row-lock + unique); expiry job vs capture одного лота → без отрицательных остатков (lockForUpdate)

**Вопросы к Гейту A:**
- Срок нового БС-лота после переноса просроченного остатка ОС→БС: спека оставила BLOCKER (BR-ACC-004), триаж DEC-015 его не закрыл. Предлагаю: 1 год с даты переноса, параметр конфига accounts.bs_lot_lifetime_days. Подтвердить?
- Допускается ли оплата заказа на 100% со счетов (ОС ≤70% + БС на остаток), т.е. заказ вообще без TON-платежа? По спеке БС используется «для покупок на общих условиях» без лимита — предлагаю да; но это создаёт путь покупки тарифа без внешних денег, что влияет на экономику (бонусы с BV, оплаченного бонусными деньгами). Подтвердить или ограничить (например, суммарно счетами ≤70%)?
- НС→ОС 1/16 до появления периодов T04: переводить весь остаток НС на момент джоба (просто) или сразу закладывать gating «только по finalized half-month» (спека 06)? Предлагаю: в T02 — весь остаток (НС пополняется только T06 при закрытии периода, к 1/16 остаток и так финализирован), gating добавит T04. Возражения?

**Допущения:**
- Контракт T01: PolicyVersionResolver отдаёт merged-конфиг на дату (configFor(date)); ключи accounts.os_order_payment_max_percent=70, os/bs_lot_lifetime_days=365, ns_transfer_days=[1,16]; если T01 назовёт иначе — правка одного адаптера в WalletAccountsV2Service
- T01 создаёт CalculatorV2ServiceProvider и строку его регистрации; T02 только дополняет provider маркер-блоком (иначе T02 создаёт его сам)
- V2-субсчета живут в ТОЙ ЖЕ таблице ledger_entries (account_type string(32) вмещает новые типы) — отдельная таблица журнала не нужна; V1 LedgerService и member_wallets не изменяются вообще
- До cutover (T15) у партнёров нет средств на ОС/НС/БС (бонусы V2 не начисляются: флаг off), поэтому хуки в OrderService/PaymentService дремлют за feature-flag mh_v2_accounts и не влияют на прод
- База 70% = order.total_usdt_cents (в IziGo заказ = тариф; доставки/налогов/подписки нет — подписка отменена решением владельца DEC-004); округление лимита вниз до цента: intdiv(total*70,100)
- Порядок списания лотов — earliest-expiry-first с полной трассировкой (DEC-015, спека B; при равных expires_at — по id ASC = FIFO)
- НС — плоский субсчёт без лотов (транзитный: структурная премия → перевод 1/16); лоты и годовой срок — только ОС и БС (BR-ACC-001/003)
- Тайминги джобов: НС→ОС 1/16 в 00:15 UTC, expiry ежедневно 00:20 UTC (границы периодов UTC по T04; DEC-019 — без переноса на праздники)
- Вывод средств — только с ОС; переключение WithdrawalService с member_available на ОС выполняет T15, T02 лишь поставляет готовые идемпотентные методы hold/release/paid
- Формат API-ответов: центы integer + человекочитаемый decimal-string по образцу WalletService::centsToDecimal; RU-строки ошибок — по существующему паттерну сервисов
- Награды (T10) зачисляются на БС кредитом через credit(account=bs) и наследуют годовой срок БС-лота; реферальная/лидерский/глобальный (T07/T08/T09) — на ОС; структурная (T06) — на НС

## T03 — PV/BV раздельно + PV-лоты бинара (volume-слой V2: снапшот заказа, лоты L/R по всем бинарным предкам, matching min(L,R) FIFO, carryover, роли веток)
Зависит от: T01 — интерфейс PolicyVersionResolver::resolveForDate(paid_at): policy_version_id (T03 объявляет узкий контракт ServicesV2/Contracts/PolicyVersionResolverInterface и биндится на реализацию T01; до merge T01 — заглушка с version_id=1)

**Таблицы:**
- products (ALTER): + bv_usd_cents unsignedBigInteger NULL (BV тарифа в USD-центах; NULL => BV = price_usdt_cents; существующий pv integer остаётся отображаемым)
- v2_order_volume_snapshots: id, order_id FK, order_item_id FK unique, member_id FK, pv decimal(18,6), bv_usd_cents bigint, policy_version_id, paid_at, created_at — immutable снапшот на момент PAID (DEC-003); контракт для T07 (реферальная база BV) и T05 (personal PV)
- v2_pv_lots: id, owner_member_id FK (бинарный предок), side varchar(5) left|right, buyer_member_id FK, origin_order_id FK, origin_order_item_id FK, pv_original/pv_available/pv_matched/pv_reversed decimal(18,6), bv_usd_cents_original bigint (BV-provenance строки), policy_version_id, state varchar(12) free|grace_held|exhausted|reversed (grace_held — задел под T05), occurred_at, timestamps; UNIQUE(origin_order_item_id, owner_member_id, side) — идемпотентность инжеста; INDEX(owner_member_id, side, state, occurred_at) — FIFO-выборка
- v2_binary_matches: id, member_id FK, period_key varchar(20) NULL (напр. 2026-07-H1; FK на периоды добавит T04/T06), run_uuid, cutoff_at, matched_pv decimal(18,6), matched_bv_usd_cents bigint (сумма BV потреблённых лотов, DEC-016; коэффициент 421.2 запрещён), status varchar(12) provisional|final|reversed, timestamps; UNIQUE(member_id, period_key, run_uuid)
- v2_pv_lot_allocations: id, binary_match_id FK, pv_lot_id FK, side, pv_consumed decimal(18,6), bv_usd_cents_consumed bigint (пропорционально pv_consumed/pv_original, округление на аллокации largest-remainder до matched_bv), created_at; UNIQUE(binary_match_id, pv_lot_id)
- v2_member_branch_stats (пересоздаваемая проекция): member_id PK/FK, left_free_pv/right_free_pv decimal(18,6), left_lifetime_pv/right_lifetime_pv decimal(18,6), large_side varchar(5) NULL (tie => NULL), small_branch_lifetime_pv decimal(18,6) (контракт для порогов малой ветки T05), recomputed_at — роли веток пересчитываются после каждого инжеста/матчинга
- feature_flags (seed): mh_v2_volumes = false (deny-by-default)

**Миграции (по порядку):**
- 2026_07_12_030000_add_bv_usd_cents_to_products.php (nullable, без backfill — NULL трактуется как BV=цена)
- 2026_07_12_030100_create_v2_order_volume_snapshots_table.php
- 2026_07_12_030200_create_v2_pv_lots_table.php
- 2026_07_12_030300_create_v2_binary_matches_table.php
- 2026_07_12_030400_create_v2_pv_lot_allocations_table.php
- 2026_07_12_030500_create_v2_member_branch_stats_table.php
- 2026_07_12_030600_seed_feature_flag_mh_v2_volumes.php (insertOrIgnore в feature_flags, выключен)
- Слот таймстампов T03: 2026_07_12_0300xx-0306xx — не пересекается с T01/T02 (договорённость: Txx => 2026_07_12_0xx000)

**Backend:**
- NEW Modules/Calculator/ServicesV2/Contracts/PolicyVersionResolverInterface.php — узкий контракт к T01 (resolveForDate(CarbonInterface): int)
- NEW Modules/Calculator/Models/V2/{OrderVolumeSnapshot,PvLot,BinaryMatch,PvLotAllocation,MemberBranchStat}.php — Eloquent-модели, casts decimal/int-центы
- NEW Modules/Calculator/DomainV2/Volume/LotMatcher.php — ЧИСТАЯ функция матчинга: вход free-лоты L/R (DTO LotSlice) + cutoff -> MatchResult{matched_pv=min(sumL,sumR), consumptions FIFO (earliest occurred_at, потом id), matched_bv_cents по потреблённым долям}; без БД — юнит-тестируемое ядро (зеркало pseudocode CAL-BIN-001)
- NEW Modules/Calculator/DomainV2/Volume/{LotSlice,MatchResult,LotConsumption}.php — DTO
- NEW Modules/Calculator/ServicesV2/Volume/OrderVolumeSnapshotService.php — captureOnPaid(Order): по order_items пишет v2_order_volume_snapshots (pv decimal, bv = qty*(product.bv_usd_cents ?? unit_price_usdt_cents), policy_version_id по paid_at); идемпотентно по unique(order_item_id)
- NEW Modules/Calculator/ServicesV2/Volume/PvLotService.php — createLotsForPaidOrder(Order): предки покупателя из members.path (ltree ancestors), сторона = position ребёнка-предка на пути покупателя (все binary descendants, DEC-055, включая spillover); insertOrIgnore по unique-ключу (AT-IDEM-001); + reverseUnmatchedLotsForOrder(orderId, reason) для ручного refund (reversal-запись, лот в state=reversed, pv_reversed; matched-лоты НЕ трогаем — помечаем связанные matches reversed-required, каскад денег — зона T06/T12)
- NEW Modules/Calculator/ServicesV2/Volume/BinaryMatchingService.php — runMatching(memberId, cutoff, periodKey, runUuid): SELECT free-лоты FOR UPDATE в FIFO-порядке, LotMatcher, персист match+allocations, декремент pv_available/инкремент pv_matched, state=exhausted при 0; carryover = просто остаток pv_available (бессрочный, DEC-018, ничего не сгорает); finalizeForPeriod(periodKey) provisional->final; идемпотентно по unique(member_id, period_key, run_uuid); вызывается под ACTIVATION_LOCK_KEY-дисциплиной ИЛИ собственным advisory-lock на member_id — денег не считает (ставки/капы/НС — T06)
- NEW Modules/Calculator/ServicesV2/Volume/BranchStatsService.php — recompute(memberId): агрегаты free/lifetime PV по сторонам из v2_pv_lots, large_side = сторона с большим lifetime PV (равенство => NULL/tie), small_branch_lifetime_pv — контракт T05; вызов после инжеста и матчинга
- NEW Modules/Calculator/ServicesV2/Volume/PaidOrderV2Pipeline.php — ЕДИНАЯ точка пост-оплатных V2-хуков: apply(Order) => если flag mh_v2_volumes: snapshot -> лоты -> branch stats, в ТОЙ ЖЕ транзакции markPaid; T05/T07 позже регистрируют СВОИ шаги сюда, а не правят markPaid (снижение конфликтов)
- NEW Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — DI-регистрации V2 (create-if-absent: если T02 смержился первым — только append своих singleton-строк); одна строка подключения из CalculatorServiceProvider::register()
- NEW Modules/Calculator/Http/Controllers/V2/VolumeAdminController.php — admin read-only: GET pv-lots (фильтры member/side/state), GET binary-matches, GET branch-stats/{member}, GET order-volume-snapshots?order_id; POST binary-matches/run (owner-only, ручной прогон для тестов/отладки до T04-джобов)
- NEW Modules/Calculator/Routes/api/v2_volumes.php — группа admin: middleware web.admin + calculator.role:owner + feature.flag:mh_v2_volumes (по образцу Routes/api/feature_flags.php)
- EDIT Modules/Calculator/Services/OrderService.php — в markPaid() после activate(): $this->v2Pipeline->apply($order) (3-5 строк, за флагом; конструктор +1 зависимость)
- EDIT Modules/Calculator/Routes/api.php — одна строка require __DIR__ . '/api/v2_volumes.php'; // T03
- EDIT Modules/Calculator/Providers/CalculatorServiceProvider.php — одна строка $this->app->register(CalculatorV2ServiceProvider::class) (если T02 ещё не добавил)
- TESTS: Tests/Unit/V2/LotMatcherTest.php, Tests/Feature/V2/{PvLotIngestTest,BinaryMatchingTest,VolumeReversalTest,VolumeAdminApiTest}.php

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php (одна строка регистрации V2-провайдера — гонка с T02)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php (create-if-absent, append-only — общий для T02/T03/T04+)
- mh-calc-backend-main/Modules/Calculator/Routes/api.php (одна require-строка в хвосте — все задачи T02-T14)
- mh-calc-backend-main/Modules/Calculator/Services/OrderService.php (markPaid-хук — также интересен T02 [оплата с ОС <=70%] и T07 [реферальная]; T03 первым вводит PaidOrderV2Pipeline как единственную точку расширения)
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ (слот таймстампов 2026_07_12_03xxxx закреплён за T03)
- mh-calc-backend-main/phpunit.xml (только если понадобится env-флаг — стремиться НЕ трогать)

**Риски конфликтов:**
- T02 (счета ОС/НС/БС): оба создают CalculatorV2ServiceProvider и строку его регистрации в CalculatorServiceProvider — merge-train: первый создаёт, второй только append; заранее согласовать скелет провайдера с маркер-блоками // T02 ... // T03
- T02 также правит путь оплаты (списание с ОС в PaymentService/OrderService) — T03 трогает ТОЛЬКО хвост markPaid (после activate); держать хук одним компактным блоком, чтобы diff не пересекался
- T07 (реферальная сразу после оплаты) захочет свой пост-оплатный хук — контракт: T07 регистрирует шаг в PaidOrderV2Pipeline, НЕ правит markPaid; зафиксировать в интерфейсе пайплайна сразу
- T06 (структурная премия) потребляет v2_binary_matches.matched_bv_usd_cents и runMatching()/finalizeForPeriod() — формат зафиксировать в этом плане, менять после merge T03 нельзя без синхрона с T06; period_key остаётся строкой до T04, FK добавляет T04/T06 своей миграцией
- T05 (лестница статусов) читает v2_member_branch_stats.small_branch_lifetime_pv и v2_order_volume_snapshots (personal PV) и добавит логику grace_held-лотов — enum state уже содержит grace_held, T05 не мигрирует схему лотов
- T12/refund: reverseUnmatchedLotsForOrder оставляет matched-лоты — каскадный reversal матчей/денег согласовать с задачей возвратов, не дублировать
- Коллизия таймстампов миграций с T01/T02 — снята диапазоном 2026_07_12_03xxxx
- PlacementAdminService (перенос узла в бинаре) меняет members.path — существующие лоты НЕ перевешиваются (provenance неизменен); если T-задача перемещений решит иначе, это их миграция, не T03

**Тест-план:**
- [ДЕНЬГИ, обяз.] Unit LotMatcher: min(L,R); FIFO earliest-first; частичное потребление лота; BV пропорционально потреблённому PV (DEC-016), суммы в центах сходятся largest-remainder (никаких потерянных центов: sum(bv_consumed)=matched_bv); decimal PV (дробные значения); L=0/R>0 => zero_explanation, полный carry; равные остатки => обе стороны 0, large_side=tie
- [ДЕНЬГИ, обяз.] Golden-кейсы спеки в USD: AT-BIN-001 (100PV vs 100PV, BV 42120 KZT -> 9000 центов по 468: matched 100, carry 0) и AT-BIN-002 (100 vs 50: matched 50, carry 50, сторона с остатком становится большой) — на уровне matched_pv/matched_bv/ролей веток (без ставок — они в T06)
- [ДЕНЬГИ, обяз.] Feature PvLotIngest: оплаченный заказ создаёт лоты у ВСЕХ бинарных предков с корректной стороной, включая spillover-потомка не из реферального дерева (DEC-055); покупатель-корень => 0 лотов; снапшот BV/PV immutable — смена цены/pv товара после PAID не меняет снапшот и лоты (DEC-003)
- [ДЕНЬГИ, обяз.] Идемпотентность: дубль PaymentConfirmed/повторный markPaid => нет дублей снапшотов/лотов (AT-IDEM-001); повторный runMatching с тем же (member, period_key, run_uuid) => no-op; конкурентные runMatching двух воркеров не задваивают потребление (FOR UPDATE)
- [ДЕНЬГИ, обяз.] Carryover: два последовательных матчинга — остаток pv_available переживает период, не сгорает, потребляется FIFO в следующем прогоне
- [ДЕНЬГИ, обяз.] Reversal: refund неспаренного лота => state=reversed, pv_reversed, free-агрегаты пересчитаны; refund заказа с уже matched-лотом => лот НЕ удаляется, match помечен на reversal (запись остаётся, история неизменна)
- [Negative, обяз.] Флаг mh_v2_volumes OFF => markPaid не пишет ни одной V2-строки, V1-активация/ledger не изменились (регрессия V1); роуты v2 отдают 404/403 за feature.flag
- [Negative, права] admin-эндпоинты: без web.admin => 401/403; роль не-owner => 403; cabinet-токен на admin-роут => 403; POST run без owner => 403
- [Проекция] BranchStats: после инжеста и матчинга free/lifetime по сторонам сходятся с суммами лотов; recompute из чистой проекции детерминированно восстанавливает те же значения
- [Инварианты БД] unique(origin_order_item_id, owner_member_id, side) и unique(binary_match_id, pv_lot_id) держат гонки; CHECK-инвариант pv_available+pv_matched+pv_reversed=pv_original проверяется тестом на каждом сценарии

**Вопросы к Гейту A:**
- BV тарифов в USD-центах: подтвердить значение BV для каждого текущего тарифа (Bronze 90PV / Silver 180PV / Gold 540PV). Дефолт плана: BV = 100% цены тарифа (bv_usd_cents NULL => price). Если BV != цене (как в легаси, где 100PV=42120 KZT при иной цене) — дать таблицу BV по тарифам до включения флага.
- Нужен ли LIVE-провизорный матчинг (пересчёт min(L,R) при каждой оплате) для карточки бинара в Mini App, или матчинг запускается ТОЛЬКО при закрытии half-month (T04/T06)? T03 отдаёт runMatching как сервис + ручной admin-запуск; live-режим добавил бы нагрузку и provisional-матчи, которые надо уметь отменять — по умолчанию НЕ включаем.
- Перемещение узла админом в бинар-дереве (PlacementAdminService) ПОСЛЕ создания лотов: существующие лоты остаются у прежних предков с прежними сторонами (provenance неизменен), новые заказы идут по новому пути. Подтвердить, что ретро-перенос лотов не требуется.

**Допущения:**
- T03 не считает деньги: ставки 5-9%, капы, НС-постинг — T06; T03 отдаёт matched_pv/matched_bv_usd_cents и API матчинга. Порядок каскада DEC-053 не затрагивается — matching это шаг до raw-бонуса.
- Контракт T01 сведён к PolicyVersionResolverInterface::resolveForDate(); конфиг ставок/капов T03 не нужен — только version_id для provenance снапшотов и лотов.
- Периодов ещё нет (T04): period_key — строка формата YYYY-MM-H1|H2, cutoff передаётся параметром; FK на таблицу периодов добавит T04/T06 отдельной миграцией.
- Лоты бессрочные, FIFO с provenance и reversal-links (DEC-018 SPEC_DEFAULT); никакого expiry-job в T03 (1-летний срок относится к денежным лотам ОС/БС в T02, не к PV-лотам).
- Популяция бинара — все binary descendants включая spillover (решение владельца DEC-055), реализуется через members.path (ltree) без обращения к sponsor_id.
- PV в V2 — decimal(18,6) по спеке (NUMERIC), деньги — integer USD-центы; products.pv (integer) остаётся отображаемым полем, источником для V2 служит снапшот.
- state=grace_held в v2_pv_lots — зарезервирован; логика grace CLIENT (7/30 дней) — целиком T05.
- Инжест V2 идёт в той же транзакции, что markPaid, под уже взятым advisory-lock активаций — отдельный лок не нужен для инжеста; для runMatching используется FOR UPDATE на лотах участника.
- Feature-flag mh_v2_volumes независим от будущего общего cutover-флага mh_plan_v2 (T15): volume-слой можно включить в shadow-режиме раньше денег.
- Фронтов в T03 нет: админ-UI по лотам/матчам — T13, Mini App — T14 (admin JSON-эндпоинты уже готовы для них).
- Названия таблиц с префиксом v2_ — чтобы не пересекаться с легаси calculator_* и читаться в forbidden-zone-ревью как новые.

## T04 — Расчётные периоды и джобы (V2): half-month/month/quarter, статусы open/closed, идемпотентные scheduled-джобы, снапшоты, запрет изменения закрытых периодов
Зависит от: T01

**Таблицы:**
- calc_periods_v2: id PK; period_type enum(half_month,month,quarter); code string (напр. '2026-07-H1','2026-07','2026-Q3'); starts_at/ends_at datetime UTC (полуоткрытый [start,end), оплата ровно 16-го 00:00 -> H2); timezone string default 'UTC'; status enum(open,closing,closed); policy_version_id FK->policy_versions (T01, резолв на starts_at); closed_at nullable; closed_by nullable ('system'|admin id); timestamps; UNIQUE(period_type,starts_at); UNIQUE(period_type,code); INDEX(status)
- calc_runs_v2: id PK; period_id FK->calc_periods_v2; run_no int (последовательный внутри периода); mode enum(preview,close) — rerun добавит T12, enum расширяемый; status enum(pending,running,succeeded,failed,superseded); input_cutoff datetime; snapshot_id nullable FK->calc_snapshots_v2; engine_version string; result_hash string nullable (sha256 результата — детерминизм: два preview на одном снапшоте дают одинаковый hash); idempotency_key string UNIQUE (напр. 'close:half_month:2026-07-H1'); step_results json nullable (метрики шагов пайплайна); error text nullable; started_at/finished_at; UNIQUE(period_id,run_no)
- calc_snapshots_v2: id PK; run_id FK->calc_runs_v2 UNIQUE; payload json (policy_version+config hash, манифест заказов/оплат окна: ids+суммы BV/PV, агрегаты wallet/счетов, timezone, rounding) — секции расширяют close-steps T06/T09/T11 через SnapshotService; payload_hash string (sha256); created_at; immutable: модель без update-пути, guard в сервисе
- calc_job_executions_v2: id PK; job_name string (напр. 'half-month-close','ns-os-transfer','quarter-payout','month-close'); window_key string (напр. '2026-07-H1','ns-os:2026-07-16','2026-Q3'); status enum(running,succeeded,failed); attempts int; started_at/finished_at; error text nullable; UNIQUE(job_name,window_key) — ядро идемпотентности джобов по окну (DEC-019): повтор succeeded-окна = no-op, running с протухшим lease перехватывается

**Миграции (по порядку):**
- 2026_07_12_040000_create_calc_periods_v2_table.php
- 2026_07_12_040100_create_calc_runs_v2_table.php
- 2026_07_12_040200_create_calc_snapshots_v2_table.php
- 2026_07_12_040300_create_calc_job_executions_v2_table.php
- 2026_07_12_040400_seed_v2_periods_feature_flag.php — сид feature_flags: 'mh_plan_v2_periods' (disabled, deny-by-default; гейтит admin-роуты и включение scheduled-джобов V2)
- Слот timestamp T04: 2026_07_12_0400xx-0404xx (диапазоны по задачам — рекомендация preflight; не пересекать с T01/T02/T03)

**Backend:**
- ServicesV2/Periods/PeriodCalendar.php — чистый калькулятор границ: halfMonthFor(at)/monthFor/quarterFor в UTC, полуоткрытые интервалы, codeFor(), previousOf(); H1=[1-е 00:00,16-е 00:00), H2=[16-е 00:00, 1-е след. мес. 00:00); квартал календарный (DEC-036 стартово)
- ServicesV2/Periods/PeriodService.php — ensurePeriod(type,date) ленивое создание строк (idempotent upsert по UNIQUE), findByCode, переходы статусов; assertOpen(period) — единый guard 'закрытый период неизменяем' для всех V2-постингов (T06-T11 обязаны звать его перед проводками; корректирующий путь T12 идёт с явным флагом correction и НЕ через этот guard)
- ServicesV2/Periods/PeriodCloseService.php — оркестратор закрытия: claim окна в calc_job_executions_v2 -> advisory-lock ACTIVATION_LOCK_KEY (тот же 0x12916001, что ActivationService — сериализация с активациями/пересчётами) -> создать calc_run (mode=close, idempotency_key) -> SnapshotService::freeze -> выполнить PeriodCloseStep-ы из registry по order() (порядок DEC-053: raw -> капы -> 60%-пул -> лидерский -> posting; сами шаги регистрируют T06/T09/T11) -> result_hash -> status=closed. Предикаты зависимостей: month close только после закрытия обоих half-month; quarter payout только после 3 закрытых месяцев (ошибка -> job FAILED без постинга, период остаётся open)
- ServicesV2/Periods/SnapshotService.php — сборка immutable-снапшота run'а: базовые секции (policy version+hash из T01 PolicyVersionResolver, манифест заказов окна, агрегаты) + API addSection() для close-steps; sha256; запрет апдейта
- ServicesV2/Periods/PeriodCloseStepRegistry.php — реестр шагов пайплайна; регистрация через tagged-биндинги в CalculatorV2ServiceProvider; supports(periodType)+order()
- ServicesV2/Contracts/PeriodCloseStep.php — interface: supports(string $periodType): bool; order(): int; execute(CalcRun $run, CalcPeriod $period): array (метрики в step_results). КОНТРАКТ для T06/T09/T11 — заморозить сигнатуру на Гейте A
- ServicesV2/Contracts/NsToOsTransferHandler.php + ServicesV2/Periods/NullNsToOsTransferHandler.php — interface transferForClosedHalfMonth(CalcPeriod $period, string $windowKey): array{members:int,cents:int}; Null-дефолт до реализации T02 (T02 реализует ledger-проводку НС->ОС, биндинг перебивается в CalculatorV2ServiceProvider)
- ServicesV2/Contracts/QuarterGlobalPayoutHandler.php + Null-реализация — interface payQuarter(CalcPeriod $quarter, array $monthPeriodIds, string $windowKey): array; реальную реализацию даёт T09
- Models/V2/CalcPeriod.php, Models/V2/CalcRun.php, Models/V2/CalcSnapshot.php, Models/V2/CalcJobExecution.php — Eloquent-модели, casts (immutable_datetime UTC), константы статусов; CalcSnapshot без fillable-update
- Console/V2/PeriodsEnsureCommand.php — calc-v2:periods-ensure, daily 00:01 UTC: идемпотентно создать текущие+следующие периоды всех трёх типов
- Console/V2/HalfMonthCloseCommand.php — calc-v2:half-month-close, daily 00:10 UTC с внутренним due-check + catch-up (закрывает ЛЮБОЙ просроченный open half-month, а не только вчерашний — устойчивость к простоям планировщика); no-op при выключенном флаге mh_plan_v2_periods
- Console/V2/NsToOsTransferCommand.php — calc-v2:ns-os-transfer, daily 00:20 UTC: due 1-го и 16-го ПОСЛЕ закрытия соответствующего half-month (порядок спеки 7.4: FINAL -> TRANSFER); window_key='ns-os:YYYY-MM-DD(01|16)'; вызывает NsToOsTransferHandler ровно один раз на окно; catch-up
- Console/V2/MonthCloseCommand.php — calc-v2:month-close, daily 00:30 UTC: закрывает просроченный месяц, предикат — оба half-month закрыты
- Console/V2/QuarterPayoutCommand.php — calc-v2:quarter-payout, daily 00:40 UTC: квартал закончился + 3 месяца закрыты -> QuarterGlobalPayoutHandler -> квартал closed; window_key='2026-Q3'
- Http/Controllers/V2/PeriodAdminController.php — index (список периодов: тип/код/границы/статус/runs), show (run'ы + мета снапшота + step_results), close (ручной идемпотентный триггер закрытия, owner-only) — контракт чтения для админки T13
- Routes/api/v2_periods.php — группа admin: middleware web.admin + calculator.role:owner + feature.flag:mh_plan_v2_periods; GET /admin/v2/periods, GET /admin/v2/periods/{id}, POST /admin/v2/periods/{id}/close (по образцу Routes/api/feature_flags.php) + ОДНА строка require в Routes/api.php
- Providers/CalculatorV2ServiceProvider.php — ПРАВКА маркер-блоком '>>> T04 periods': singletons сервисов периодов, Null-биндинги двух handler-контрактов, registerCommands (5 команд), registerCommandSchedules (5 расписаний с withoutOverlapping(30) и ->when(flag)); провайдер создаётся T01 (контракт), fallback: если T01 его не создал — создать здесь + одна строка регистрации в CalculatorServiceProvider::register()

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — горячий файл волны (T02/T03/T04 правят параллельно); писать только внутрь маркер-блока '>>> T04'
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php — только если T01 не создал V2-провайдер (одна строка register); иначе НЕ трогать
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require __DIR__.'/api/v2_periods.php' в конце (конфликт-зона со всеми фичевыми задачами, разрешается тривиально)
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — слот 2026_07_12_04xxxx зарезервирован за T04
- feature_flags (таблица) — сид флага mh_plan_v2_periods отдельной миграцией; с чужими сидами флагов не конфликтует (разные файлы/ключи)

**Риски конфликтов:**
- T02 (параллельная волна, оба зависят только от T01): двойное владение 'переводы НС->ОС 1/16' — T04 владеет командой/расписанием/идемпотентностью окна, T02 владеет ledger-проводкой. Контракт NsToOsTransferHandler (сигнатура выше) нужно зафиксировать на Гейте A, иначе T02 реализует свой сервис с несовместимой сигнатурой и T06 придётся писать адаптер
- T06/T09/T11: интерфейс PeriodCloseStep — межзадачный контракт пайплайна закрытия; менять сигнатуру после старта T06 нельзя (breaking). Порядок шагов по DEC-053 кодируется order()-константами, реестр у T04
- T09: QuarterGlobalPayoutHandler — та же схема Null-биндинг/перебиндинг; T09 также захочет писать месячные аллокации в month-close pipeline — это его PeriodCloseStep, не правка T04-файлов
- CalculatorV2ServiceProvider — самый горячий файл волны T02/T03/T04: только маркер-блоки, merge-train сериализует
- Advisory-lock ACTIVATION_LOCK_KEY (0x12916001): переиспользование в PeriodCloseService сериализует закрытия с активациями V1 (защита от задвоения проводок), но удлиняет удержание лока — при cutover (T15) учесть; ключ менять нельзя без согласования
- T12 (возвраты): корректирующие проводки в закрытые периоды пойдут МИМО assertOpen через отдельный correction-путь — T12 не должен ослаблять guard, а добавлять свой параллельный вход; зафиксировать в контракте guard'а
- T13 (админка периодов): читает GET-эндпоинты T04 — формат ответа PeriodAdminController заморозить на Гейте A
- phpunit.xml НЕ трогаем (env-переменные не нужны — флаг сидится миграцией), риск конфликта снят

**Тест-план:**
- Unit PeriodCalendarTest: границы H1/H2 для 28/29/30/31-дневных месяцев (февраль, високосный 2028), month, calendar quarter; полуоткрытость — момент ровно 16-е 00:00:00 UTC принадлежит только H2, 1-е 00:00 только новому месяцу (аналог API-TIME-01); codeFor/previousOf детерминированы
- Feature PeriodLifecycleTest: periods-ensure идемпотентен (двойной запуск — те же строки, UNIQUE не нарушен); policy_version_id резолвится на starts_at через контракт T01; переходы open->closing->closed; assertOpen на closed-периоде кидает исключение (ДЕНЬГИ: guard обязателен)
- Feature PeriodJobsIdempotencyTest (ДЕНЬГИ, обязательный): calc-v2:half-month-close дважды на одном окне -> один calc_run, одна succeeded-запись calc_job_executions_v2, второй запуск no-op; ns-os-transfer дважды -> NsToOsTransferHandler вызван ровно один раз (spy); имитация конкурентного запуска (вторая вставка window_key ловит unique violation -> корректный no-op, не exception наружу)
- Feature PeriodCloseOrderingTest (ДЕНЬГИ): month-close при одном незакрытом half-month -> BLOCKED/failed без closed-статуса и без вызова шагов; quarter-payout при 2 из 3 закрытых месяцев -> no-op/failed; после закрытия третьего — payout handler вызван один раз; transfer 16-го НЕ выполняется, пока H1 не закрыт (порядок FINAL->TRANSFER)
- Feature catch-up: планировщик 'проспал' 16-е — запуск 17-го закрывает H1 и делает перевод с window_key 16-го (одно окно, не два)
- Feature SnapshotTest: снапшот создаётся до шагов, payload_hash стабилен; два preview-run на идентичных входах дают одинаковый result_hash (ARCH-NFR-01); попытка изменить снапшот — исключение
- Feature PeriodAdminApiTest (negative-cases прав): без web.admin -> 401/403; роль не-owner -> 403; флаг mh_plan_v2_periods выключен -> отказ (deny-by-default); POST close идемпотентен (повтор -> тот же run)
- Feature FlagGateTest: при выключенном mh_plan_v2_periods все 5 команд — немедленный no-op (ни строк в calc_job_executions_v2, ни периодов); V1-поведение не затронуто (существующие тесты LedgerServiceTest/OrderActivationTest зелёные без изменений)
- Unit PeriodCloseStepRegistry: шаги исполняются строго по order(), supports() фильтрует по типу периода; исключение в шаге -> run failed, период остаётся open, постингов нет (никакого частичного closed)

**Вопросы к Гейту A:**
- Время НС->ОС относительно 60%-калибровки полумесячного периода (OPEN-POOL-02): подтвердите порядок 'перевод 1/16 выполняется ТОЛЬКО из уже закрытого half-month (после капов и 60%-калибровки этого периода)'. Альтернатива 'перевод до калибровки + корректировка задним числом' в план не заложена и противоречит запрету изменения закрытых сумм
- Reopen закрытых периодов: план НЕ предусматривает админский reopen вовсе (только корректирующие проводки T12, спека же допускает REOPENED с approval). Подтвердите, что reopen не нужен — это упрощает инварианты денег
- Время запуска джобов 00:01/00:10/00:20/00:30/00:40 UTC (границы периодов — UTC по роадмапу): ок, или привязать запуски к утру Алматы (границы остаются UTC)? Влияет только на задержку начислений, не на суммы
- Фиксация контракта на Гейте A между параллельными T02/T04: интерфейс NsToOsTransferHandler::transferForClosedHalfMonth(CalcPeriod, string $windowKey) реализует T02 — нужно явно передать сигнатуру в план T02, иначе волна разъедется

**Допущения:**
- T01 создаёт CalculatorV2ServiceProvider и PolicyVersionResolver с методом вида forDate(DateTimeInterface): PolicyVersion (valid_from/valid_to по роадмапу); T04 только добавляет маркер-блок. Fallback в плане: если провайдера нет — T04 создаёт его + одна строка в CalculatorServiceProvider::register()
- Границы периодов — UTC (явно из фокуса T04 роадмапа; осознанное отступление от Asia/Almaty спеки, колонка timezone оставлена на будущее); event time = paid_at (спека 7.2)
- Кварталы — календарные (DEC-036: конфигурируемый fiscal quarter, стартово календарный; схему при необходимости добавит конфиг PolicyVersion T01)
- Статусы периодов упрощены до open/closing/closed (роадмап: 'статусы open/closed'); SOFT_CLOSED/REOPENED/SUPERSEDED из спеки не реализуются в T04 — rerun/reversal-механику добавляет T12 поверх mode-enum calc_runs_v2
- T04 не постит деньги сам: все ledger-эффекты закрытия идут через PeriodCloseStep (T06/T09/T11) и handler-контракты (T02/T09); Null-реализации делают T04 безопасно мерджимым первым в волне
- Тип INSTANT из спеки (реферальная премия 'сразу') периодом не моделируется — T07 постит на ОС событийно при оплате, half-month/month периоды нужны ему только для атрибуции отчёта 60%-пула; ROLLING_30D (grace Клиента) — зона T05, не T04
- Фон = планировщик Laravel (schedule:work), НЕ queue — по действующему паттерну проекта; идемпотентность окон через calc_job_executions_v2 + withoutOverlapping
- Джобы гейтятся feature-флагом mh_plan_v2_periods (deny-by-default) внутри команд — до включения V2 прод-поведение не меняется; финальный cutover-флаг общего расчёта — зона T15
- 5 участников прода: объёмы ничтожны, batch-обход участников в transfer/close без чанкинга допустим (интерфейсы позволяют добавить чанкинг позже)

## T05 — Лестница 12 статусов + CLIENT + тиры (движок V2, монотонные ранги, снапшоты квалификации)
Зависит от: T03 — событие/хук «заказ оплачен» с immutable снапшотом PV/BV на OrderItem (DEC-003); проекция бинарных объёмов: lifetime credited PV по сторонам L/R каждого участника (все binary descendants, DEC-055) — T05 объявляет интерфейс ServicesV2/Contracts/BinaryVolumeReaderInterface { leftLifetimePv(memberId, asOf), rightLifetimePv(memberId, asOf) }, T03 его имплементирует, T03 — API аннулирования grace-лотов: интерфейс ServicesV2/Contracts/PvLotAnnulmentInterface { annulBeneficiaryLots(memberId, until, reason, idempotencyKey) } для необратимого обнуления PV, накопленных CLIENT-ом за grace-период (BR-REG-004), T01 (транзитивно через T03) — PolicyVersion-конфиг: каталог 12 статусов (пороги малой ветки 1k..3M PV), определения 3 вариантов квалификации (счётчики, comparator exact|at_least), пороги тиров START/BUSINESS/ELITE (100-199/200-599/600+). T05 только парсит секции ranks/tiers, не владеет схемой

**Таблицы:**
- v2_partner_states — member_id (PK, FK members), state (enum: none|client|client_grace|consultant_pending_annul|consultant|grace_expired), current_rank_code, current_tier (null|start|business|elite), personal_pv_total (decimal 12,2), client_achieved_at, grace_started_at, grace_deadline_at (UTC; дедлайн = конец 30-го календарного дня 23:59 Asia/Almaty, DEC-026), grace_outcome (null|consultant|annulled), grace_annulled_at, updated_at; денормализованная проекция для чтения T06/T07/T14
- v2_tier_history — id, member_id, tier, basis_personal_pv (накопленный personal PV на момент), tier_before, source_order_id (FK orders, nullable), policy_version_id, effective_at, created_at; append-only, тир не понижается; unique(member_id, tier)
- v2_qualification_evaluations — id (uuid), member_id, target_rank_code, as_of, policy_version_id, small_branch_pv (decimal), variant_used (1|2|3, null если fail), passed (bool), qualifiers_json (список {qualifier_partner_id, root_branch_member_id, rank_code_as_of, slot: anchor|support}), criteria_json (per-criterion rule_id/actual/required/passed/reason), evidence_hash, trigger (order|grace|manual|migration), created_at; полный снапшот по BR-RANK-002/спека 05 §4.6
- v2_rank_history — id, member_id, rank_code, rank_ordinal (int, для сравнений «минус два статуса» в T08), achieved_at, evaluation_id (FK v2_qualification_evaluations), policy_version_id, created_at; unique(member_id, rank_code) — монотонный «ранг навсегда» (DEC-020) и одновременно источник entitlement для наград T10; при скачке через ранги пишется ОТДЕЛЬНАЯ строка на каждый пройденный ранг с одним evaluation_id (DEC-040)

**Миграции (по порядку):**
- 2026_07_12_050000_create_v2_partner_states_table.php (слот T05 = 2026_07_12_0500xx, не пересекается с другими задачами)
- 2026_07_12_050100_create_v2_tier_history_table.php
- 2026_07_12_050200_create_v2_qualification_evaluations_table.php
- 2026_07_12_050300_create_v2_rank_history_table.php (FK на evaluations — строго после 050200)
- 2026_07_12_050400_seed_v2_statuses_feature_flag.php — сид флага mh_v2_statuses=off в feature_flags (deny-by-default, по образцу C3)

**Backend:**
- Modules/Calculator/DomainV2/Rank/RankCatalog.php + RankDefinition.php — парсинг секции ranks из PolicyVersion-конфига T01: 12 кодов CLIENT..VICE_PRESIDENT, ordinal, min_small_branch_pv, варианты; чистые VO без Eloquent
- Modules/Calculator/DomainV2/Rank/QualificationVariant.php — VO варианта: requirements (anchor rank+count, support rank+count, где искать L1 vs depth), comparator exact|at_least из конфига (дефолт: вариант 1 exact, варианты 2-3 at_least — по роадмапу; расхождение с DEC-022 вынесено в вопрос Гейта A)
- Modules/Calculator/DomainV2/Rank/RootBranchResolver.php — BR-TREE-001: корневая ветвь кандидата X для получателя P = первый узел после P на пути sponsor_id; чистая функция над картой sponsor_id (members)
- Modules/Calculator/DomainV2/Rank/DistinctBranchAssigner.php — детерминированный bipartite matching (DEC-023/024): кандидаты → слоты (anchor L1 + support), один кандидат не считается дважды, два лидера в одной корневой ветви = один слот (пример Директора S38), вариант 2 = минимум 5 различных корневых ветвей; жадный алгоритм с детерминированной сортировкой (rank desc, member_id asc) + fallback полный перебор для малых N
- Modules/Calculator/DomainV2/Rank/RankEvaluator.php — evaluateRank по псевдокоду CAL-RANK-001: сверху вниз по каталогу, small_branch_pv-гейт, перебор вариантов, возврат Qualified(rank, variant, assignment) или Fail с reason-кодами; только повышение (DEC-020)
- Modules/Calculator/DomainV2/Tier/TierResolver.php — чистый resolveTier(personalPvTotal) с границами 100/200/600 и правилом «тир не понижается»
- Modules/Calculator/ServicesV2/Contracts/BinaryVolumeReaderInterface.php + PvLotAnnulmentInterface.php — контракты к T03 (T05 объявляет, T03 имплементирует; на время разработки T05 — in-memory фейк в тестах)
- Modules/Calculator/ServicesV2/Status/ClientLifecycleService.php — onQualifyingOrderPaid (первая оплата >=100 PV → state=client, grace_started_at=момент CLIENT, дедлайн 30 дней вкл. Asia/Almaty), onPersonalReferralActivated (реферал с оплатой >=100 PV в grace → CONSULTANT, PV сохраняются), expireGrace (дедлайн прошёл → state=grace_expired + вызов PvLotAnnulmentInterface, идемпотентно по grace_outcome)
- Modules/Calculator/ServicesV2/Status/RankEvaluationService.php — оркестратор: собрать входы (BinaryVolumeReader, referral tree из members.sponsor_id, текущие ранги из v2_partner_states), прогнать RankEvaluator для затронутого участника и его sponsor-аплайна, персистить evaluation + rank_history (при скачке — строки по всем пройденным рангам, DEC-040), обновить v2_partner_states.current_rank_code; выполняется в транзакции под существующим advisory-lock ACTIVATION_LOCK_KEY, чтобы не гоняться с recompute V1
- Modules/Calculator/ServicesV2/Status/TierService.php — applyPaidOrder(memberId, orderId, orderPv): инкремент personal_pv_total, resolveTier, append v2_tier_history при повышении, tier_before/tier_after в строке истории
- Modules/Calculator/ServicesV2/Status/StatusReadService.php — read-API для контроллеров и соседних задач: rankAsOf(memberId, at) (по v2_rank_history — контракт для T06/T08/T09), tierAsOf(memberId, at) (контракт для T07), прогресс к следующему статусу (для T14)
- Modules/Calculator/Models/V2/V2PartnerState.php, V2RankHistory.php, V2QualificationEvaluation.php, V2TierHistory.php — Eloquent-модели
- Modules/Calculator/Console/V2/ClientGraceScanCommand.php — идемпотентный скан просроченных grace (state=client_grace AND deadline < now AND grace_outcome IS NULL), everyFifteenMinutes + withoutOverlapping; регистрация команды/расписания — в CalculatorV2ServiceProvider (общий файл, см. конфликты)
- Modules/Calculator/Http/Controllers/V2/StatusController.php — cabinet (telegram.auth): GET мой статус/прогресс/тир/история; Modules/Calculator/Http/Controllers/V2/StatusAdminController.php — admin (web.admin + calculator.role:owner): статус участника, список evaluations, детали evaluation (qualifiers + root branches), ручной пересчёт участника
- Modules/Calculator/Routes/api/v2_statuses.php — свой роут-файл по образцу feature_flags.php, вся группа под middleware feature.flag:mh_v2_statuses; + ОДНА строка require в Routes/api.php
- Тесты: Tests/Unit/V2/Rank/{RootBranchResolverTest, DistinctBranchAssignerTest, RankEvaluatorTest, TierResolverTest}.php; Tests/Feature/V2/{ClientLifecycleTest, RankEvaluationPersistenceTest, StatusApiTest, GraceScanCommandTest}.php

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php (создаётся T01/T02; T05 добавляет DI-биндинги Status-сервисов + регистрацию ClientGraceScanCommand и его расписание блочным маркером «>>> T05 statuses») — главный хотспот блока
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require __DIR__ . '/api/v2_statuses.php' в хвосте (тот же одно-строчный конфликт у T02/T03/T06-T14)
- Схема PolicyVersion-конфига T01 (секции ranks/tiers) — T05 читает ключи; любое переименование в T01 ломает RankCatalog
- Точка V2-хука после markPaid/applyPaid (владелец — T03): T05 подписывает TierService/ClientLifecycleService/RankEvaluationService на диспетчер T03, не заводит свой параллельный хук
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — общий каталог, коллизии только по timestamp; T05 держится слота 2026_07_12_0500xx

**Риски конфликтов:**
- T01: контракт схемы конфига (ranks: коды, ordinal, пороги, варианты с comparator; tiers: границы) — зафиксировать ключи ДО кодинга обеих задач, иначе RankCatalog и редактор T13 разъедутся
- T03: три контракта сразу — (а) событие «заказ оплачен V2» с PV-снапшотом, (б) BinaryVolumeReaderInterface (lifetime PV по сторонам, включая consumed лоты, исключая аннулированные), (в) PvLotAnnulmentInterface для grace; интерфейсы объявляет T05 в ServicesV2/Contracts — согласовать сигнатуры в merge-train до волны кодинга T03
- T06/T08/T09: читают достигнутый ранг через StatusReadService::rankAsOf / v2_rank_history — если T06 начнёт читать v2_partner_states.current_rank_code напрямую, исторические пересчёты half-month сломаются; контракт: as-of чтение только через rank_history
- T07: tierAsOf на момент оплаты покупки — тот же принцип as-of чтения из v2_tier_history
- T10: unique(member_id, rank_code) в v2_rank_history — это идемпотентный триггер наград; если T05 не запишет промежуточные ранги при скачке (DEC-040), T10 недоплатит награды; зафиксировано в тестах T05
- T12: реверсы уменьшают personal PV/branch PV, но ранг и тир не отзываются (DEC-020/DEC-027) — T12 не должен трогать v2_rank_history/v2_tier_history, только PV-базу; иначе конфликт семантики «навсегда»
- CalculatorV2ServiceProvider: одновременные правки T02/T04/T05/T06+ — сериализовать через merge-train, маркер-блоки по задачам
- Advisory-lock ACTIVATION_LOCK_KEY: T05 выполняет оценку рангов под тем же локом, что V1 recompute и будущий V2-пайплайн T03 — согласовать с T03, кто открывает транзакцию, чтобы не было вложенных локов/дедлоков

**Тест-план:**
- Unit RootBranchResolver: кандидат на глубине N → корневая ветвь = L1-узел; два кандидата в одной ветви → один root_branch_id; кандидат вне поддерева получателя → null/исключение
- Unit DistinctBranchAssigner (деньги: ранг определяет ставки 5-9%, капы, глубину лидерского — обязательные): пример Директора S38 (2 Gold в одной корневой ветви = 1 слот → fail до появления Gold в новой ветви); вариант 2 = anchor L1 + 4 support в 5 РАЗНЫХ ветвях; кандидат не используется дважды (anchor не закрывает support-слот); вариант 3 = 8 ветвей; детерминизм (перестановка входа → тот же результат)
- Unit RankEvaluator: границы PV 149999.99 → не Director, 150000 → проходит PV-гейт; comparator exact (вариант 1: Platinum на L1 не закрывает слот «2 Gold» при exact) vs at_least (закрывает) — оба режима из конфига; монотонность: evaluator никогда не возвращает ранг ниже текущего; скачок Consultant→Silver → rank_history получает строки Manager+Bronze+Silver с одним evaluation_id (контракт T10, DEC-040)
- Unit TierResolver: 99.99→none, 100→START, 199.99→START, 200→BUSINESS, 599.99→BUSINESS, 600→ELITE; накопительность (90+180=270→BUSINESS); тир не понижается при последующей реверс-переоценке базы
- Feature ClientLifecycle: оплата >=100 PV → state=client + grace_deadline = конец 30-го календарного дня 23:59 Asia/Almaty (проверка граничного дня включительно, DEC-026); личный реферал с оплатой >=100 PV на 30-й день → CONSULTANT, аннулирование НЕ вызывается; реферал с оплатой 90 PV → НЕ квалифицирует; истечение → ровно один вызов PvLotAnnulmentInterface с идемпотентным ключом (повторный прогон команды скана — no-op, grace_outcome уже annulled)
- Feature RankEvaluationPersistence: evaluation сохраняет variant_used, qualifiers_json с root_branch_member_id и rank_code_as_of, evidence_hash; повторная оценка без изменений сети → passed evaluation, но НЕТ дублей в rank_history (unique guard); пересчёт аплайна: покупка на глубине 3 триггерит оценку всех sponsor-предков
- Feature StatusApi (negative обязательные — права): cabinet-роуты без telegram.auth → 401; участник видит только свой статус (чужой member_id → 403/404); admin-роуты под web.admin+role:owner → не-owner 403; флаг mh_v2_statuses OFF → вся группа недоступна; ручной пересчёт админом пишет audit-строку
- Feature GraceScanCommand: withoutOverlapping, batch из N просроченных → N аннулирований, повторный запуск → 0; grace_expired участник после появления реферала позже дедлайна НЕ становится CONSULTANT автоматически без новых объёмов (по BR-REG-004 объёмы считаются только после появления реферала — зафиксировать выбранную семантику тестом)
- Contract-тесты читающих API для соседей: rankAsOf(member, дата до достижения)=предыдущий ранг; tierAsOf на момент конкретного заказа = tier_before этого заказа

**Вопросы к Гейту A:**
- Тарифы vs пороги: Bronze = 90 PV, а CLIENT/активация требует >=100 PV — покупатель одного Bronze НЕ становится CLIENT и не получает тир. Что делаем: (а) поднять PV Bronze до 100, (б) снизить порог CLIENT/START до 90, (в) оставить как есть (Bronze не активирует)? Связано: триаж DEC-010 определял тир как «максимальный купленный тариф», а роадмап T05 — «по накопленному personal PV» (тогда Silver 180=START, Gold 540=BUSINESS, ELITE только суммой покупок). Планирую по роадмапу (накопленный PV) — подтвердите
- Comparator варианта 1: роадмап фиксирует «вариант 1 exact-rank», но триаж DEC-022 (SPEC_DEFAULT) принял «at least (и выше)» для всех вариантов. Реализую конфигом per-variant с дефолтом по роадмапу (v1=exact, v2/v3=at_least) — подтвердите дефолт, это влияет на скорость карьерного роста
- Grace-старт: спека привязывает 30 дней к «бинарному утверждению» (BR-REG-003, ручное утверждение спонсором / автоплейсмент через 7 дней), но в IziGo плейсмент происходит сразу при создании участника без ручного гейта. Планирую grace от момента достижения CLIENT (= активация первым заказом >=100 PV). Ок? (Альтернатива — вводить в V2 ручное утверждение плейсмента, это заметный доп. скоуп)

**Допущения:**
- PV малой ветки для рангов = lifetime накопленный PV по бинарным сторонам (consumed + free лоты, минус аннулированные grace/реверсы), а НЕ текущие free PV бинара — «за весь период» по BR-RANK-002; поставляется T03 через BinaryVolumeReaderInterface
- Каталог рангов/вариантов/тиров живёт в PolicyVersion-конфиге T01; T05 не заводит своих таблиц-справочников
- v2_rank_history с unique(member_id, rank_code) — официальный контракт-триггер наград T10; отдельной events/outbox-таблицы не заводим (очередей в проекте нет, всё синхронно + scheduler)
- Оценка рангов запускается синхронно после каждого оплаченного квалифицируемого заказа для покупателя и его sponsor-аплайна (прод крошечный — 5 участников; оптимизация батчем — забота T15+), плюс при grace-переходах и вручную админом
- V1 (members.rank_id, Domain/Rank) не трогаем; V2-статусы живут в своих таблицах до cutover T15; маппинг существующих участников — задача T15
- Grace-дедлайн храним в UTC, вычисляем как конец 30-го календарного дня 23:59:59 Asia/Almaty (DEC-006/DEC-026, вариант B «включительно»)
- Фича-гейт — существующий FeatureFlagService, флаг mh_v2_statuses, выключен по умолчанию; реальный гейт на бэке (middleware feature.flag)
- Статус «Стажёр» не реализуем (DEC-056); подписка не реализуется (DEC-004 owner) — eligibility по подписке в квалификациях отсутствует
- CONSULTANT-квалифицирующий реферал = активированный партнёр с оплаченным заказом >=100 PV (DEC-021), проверяется по факту оплаты заказа реферала, а не по регистрации

## T06 — Структурная премия (бинар) 5-9% от BV matched PV с полумесячными/месячными капами, начисление на НС при закрытии half-month окна (движок V2)
Зависит от: T02, T03, T04, T05

**Таблицы:**
- v2_structure_bonuses — одна строка на (period_id half-month, member_id), unique(period_id, member_id); колонки: id PK; period_id FK на таблицу периодов T04; member_id FK members; policy_version_id FK (T01); rank_code string (снапшот достигнутого ранга на конец окна, DEC-017 + «ранг навсегда» DEC-020); rate_bps int (500..900); matched_pv decimal(14,2); matched_bv_cents bigint (BV потреблённых лотов по DEC-016, из ответа matching API T03); match_group_id (ссылка на match/allocation-группу T03, для reversals T12); gross_cents bigint; half_cap_cents bigint; monthly_cap_cents bigint; cap_remaining_before_cents bigint; after_cap_cents bigint (после индивидуальных капов, ДО 60%-пула — это вход T11); pool_coefficient decimal(12,8) NULL и pool_adjustment_cents bigint NULL (заполняет T11, поля резервируются сейчас чтобы T11 не мигрировал чужую таблицу); net_cents bigint (сумма posting; = after_cap_cents до появления T11; база лидерского T08 по DEC-029); status enum calculated|posted|reversed; posting_idempotency_key string unique NULL (формат v2:structure:{period_id}:{member_id}); explanation json (DEC-054: входные лоты/allocations, ранг, ставка, шаги cap, версия политики); timestamps. Нулевые результаты (matched_pv=0 или cap=0) тоже сохраняются строкой для explainability
- Отдельная таблица использования капов НЕ нужна: месячное использование = SUM(after_cap_cents) по окнам месяца из v2_structure_bonuses (капы применяются ДО пула по DEC-053, поэтому база использования — after_cap, а не net)
- Чужие таблицы (только читаю/вызываю API): PV-лоты и allocations T03; периоды half-month T04; субсчёт НС в ledger T02; достигнутые ранги T05; конфиг ставок/капов PolicyVersion T01

**Миграции (по порядку):**
- 2026_07_12_060000_create_v2_structure_bonuses_table.php — единственная миграция T06; слот таймстампов задачи 2026_07_12_0600xx (диапазон по номеру задачи, не пересекается с T01-T05: 01xx-05xx и T07+: 07xx+); FK на таблицы T02/T03/T04 — nullable-ссылки по id без жёстких constraint на таблицы других задач, если их имена не зафиксированы к началу волны кодинга (иначе deadlock порядка миграций между задачами волны)

**Backend:**
- mh-calc-backend-main/Modules/Calculator/DomainV2/Bonus/StructureBonusCalculator.php — чистая математика без I/O: (matched_bv_cents, rate_bps, half_cap_cents, monthly_cap_cents, monthly_used_cents) -> {gross_cents, after_cap_cents, cap_remaining_before_cents}; округление на финальной сумме intdiv/floor (DEC-002, режим из PolicyVersion); никаких неявных коэффициентов типа 421.2 (запрещено спекой)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Bonus/StructureBonusService.php — оркестрация расчёта окна: по всем участникам с достигнутым рангом >= CONSULTANT (accessor T05) вызвать matching API T03 (min free L/R, FIFO-потребление лотов с BV-provenance; при решении Гейта A «матчить до капа» — передать лимит BV = remaining_cap/rate), снапшот ранга на конец окна, вызов калькулятора, запись строк v2_structure_bonuses со status=calculated; идемпотентно по (period_id, member_id); отказ при закрытом периоде (контракт T04)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Bonus/StructureBonusPostingService.php — ОТДЕЛЬНЫЙ шаг posting: строки calculated -> проводка на субсчёт НС через API счетов T02 (двойная запись, idempotency key = posting_idempotency_key, механизм alreadyPosted() из LedgerService), status=posted; разнесение calculate/post — точка вставки 60%-пула T11 между ними (порядок DEC-053: raw -> капы -> пул -> база лидерского -> posting)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Periods/Steps/StructureBonusCloseStep.php — адаптер к close-pipeline T04 (half-month-close H1 16-го / H2 1-го): вызывает StructureBonusService затем PostingService; пока T11 не влит — posting сразу после капов
- mh-calc-backend-main/Modules/Calculator/Models/V2/StructureBonus.php — Eloquent-модель
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2/StructureBonusController.php — admin: список по периоду + breakdown/explanation по участнику (DEC-054); cabinet: свои начисления (история для будущего T14)
- mh-calc-backend-main/Modules/Calculator/Routes/api/v2_structure_bonus.php — свой роут-файл по образцу Routes/api/feature_flags.php: cabinet-группа (telegram.auth) + admin-группа (web.admin + calculator.role:owner), вся группа под feature.flag:mh_plan_v2; в Routes/api.php — одна строка require
- mh-calc-backend-main/Modules/Calculator/Console/V2/StructureBonusRunCommand.php — ручной идемпотентный перезапуск расчёта окна для диагностики/восстановления (v2:structure-bonus:run {period}); в расписание НЕ ставится — триггер закрытия окна принадлежит T04
- Регистрация DI/команды/step — маркер-блок T06 в CalculatorV2ServiceProvider.php (файл создаёт более ранняя V2-задача; основной CalculatorServiceProvider.php НЕ трогаю)

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — правят все V2-задачи T02-T10; T06 добавляет только маркер-блок (binding сервисов, команда, регистрация close-step)
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require в хвосте (конфликт-паттерн известен, все задачи добавляют по строке)
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — общий каталог, изоляция слотом таймстампов 0600xx
- phpunit.xml — НЕ трогаю (новых env-переменных T06 не вводит)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php — НЕ трогаю (регистрация через V2-провайдер)

**Риски конфликтов:**
- T03 (самый острый): контракт matching API — сигнатура consume/match, формат ответа {matched_pv, matched_bv_cents, match_group_id, allocations}; если Гейт A решит «матчить только до капа», T03 обязан поддержать параметр лимита (BV-cap) в matching — этот контракт надо зафиксировать ДО волны кодинга, иначе взаимная блокировка T03/T06
- T04: контракт close-pipeline (интерфейс step, порядок шагов внутри закрытия, гарантия «закрытый период не пересчитывается», idempotency run key) и момент снапшота ранга «конец окна»; также порядок джобов 1-го/16-го числа: закрытие окна vs перевод НС->ОС (вопрос Гейта A)
- T02: имена констант субсчёта НС и сигнатура bonus-posting API; риск двойной ответственности — T06 НЕ делает перевод НС->ОС, только начисление на НС; согласовать, что sweep T02 подхватывает проводки T06 по типу счёта, а не по спискам ключей
- T08/T11/T12 читают v2_structure_bonuses: колонки after_cap_cents (вход T11), net_cents (база T08 по DEC-029), match_group_id + posting_idempotency_key + status (reversals T12) — контракт схемы заморожен этим планом; менять колонки после старта T08/T11 нельзя без синхронизации
- T05: accessor достигнутого ранга на произвольный момент (конец окна); если T05 даст только «текущий ранг», при закрытии окна задним числом снапшот будет неверным
- CalculatorV2ServiceProvider и Routes/api.php — механические merge-конфликты со всеми соседями волны; решается маркер-блоками и merge-train
- T01 транзитивно: ключи конфига structure (rate_bps по 12 статусам, monthly/half cap в USD-центах: 500/1000/1500/2000/5000/10000/15000/25000(Pearl DEC-039)/30000/35000/40000 USD, half=/2) — имена ключей PolicyVersion зафиксировать до кодинга

**Тест-план:**
- [деньги, обязательный] Unit golden из спеки в USD-центах по курсу 468: 100PV vs 100PV, Manager 5%, BV 42120 KZT -> matched_bv=9000 центов, премия=450 центов; остатки L=R=0
- [деньги, обязательный] Unit golden partial match: 100PV vs 50 free PV -> matched_pv=50, matched_bv=4500 центов, премия=225 центов, carryover 50 PV на большой стороне без сгорания
- [деньги, обязательный] Капы: gross > half cap -> after_cap = остаток лимита; повторный расчёт в том же месяце уменьшает monthly-остаток; НЕиспользованный лимит окна H1 не переносится в H2 (H2 стартует с полного half cap); monthly-safety: Σ after_cap двух окон <= monthly cap
- [деньги, обязательный] Повышение ранга между окнами (ранг навсегда, монотонно): H1 по капам/ставке старого ранга, H2 — нового; снапшот ранга на конец окна
- [деньги, обязательный] Исчерпанный cap (remaining=0): ветка по решению Гейта A — либо лоты матчатся и сгорают при нулевой выплате, либо матчинг не выполняется и лоты остаются free; тест пишется под принятое решение, второй сценарий фиксируется как запрещённый
- [деньги, обязательный] Идемпотентность: двойной запуск расчёта и двойной posting одного окна -> ровно один набор строк и одна группа ledger-проводок (alreadyPosted no-op); инвариант Σdebit=Σcredit после posting
- [деньги, обязательный] Направление денег: posting попадает на субсчёт НС (счета T02), member_available/ОС не затронут; T06 не делает никаких НС->ОС движений
- [деньги, обязательный] Округление: кейс с дробными центами (например ставка 6% от нечётного matched_bv) -> детерминированный floor на финальной сумме, без накопления субцентовых остатков
- [обязательный] Eligibility: участник CLIENT (ранг < CONSULTANT) — премия не считается, лоты НЕ потребляются и остаются free; участник без обеих ног — zero-строка с explanation
- [обязательный] Закрытый период: попытка расчёта/пере-posting по закрытому периоду отвергается (контракт T04)
- [negative, права] admin-роуты без роли owner -> 403; без web.admin -> 401; cabinet видит ТОЛЬКО свои строки (чужой member_id -> пусто/404); feature flag mh_plan_v2 OFF -> роуты недоступны (deny-by-default)
- [контракт] Схема-тест reversal-готовности: у posted-строки заполнены match_group_id, posting_idempotency_key, status — контракт для T12
- [контракт] Explanation JSON содержит: policy_version_id, rank_code, rate_bps, список потреблённых allocations, шаги cap — контракт explainability DEC-054 и отчёта T11

**Вопросы к Гейту A:**
- Судьба matched PV при недостаточном/исчерпанном денежном капе (спека ЯВНО оставила открытым вместе с DEC-017/018, триаж не закрыл): (А) матчить весь min(L,R), платить до капа, весь matched PV сгорает (буквальный псевдокод спеки) или (Б) матчить PV только в объёме, покрываемом остатком капа (maxBV = remaining_cap / rate), остальное остаётся в carryover. Рекомендация: Б — согласуется с решением «carryover без сгорания» (T03) и щадит партнёра; влияет на контракт matching API T03 (нужен параметр-лимит)
- Лаг НС->ОС для премии окна: закрытие H1/H2 и перевод НС->ОС происходят в одни даты (1-е/16-е). Премия, начисленная на НС при закрытии окна, переводится на ОС тем же числом сразу после закрытия (НС фактически транзитный, деньги доступны сразу) или только следующей датой 1/16 (клиринговый лаг ~15 дней)? Формулировка PPTX «НС -> ОС 1-го/16-го» допускает оба чтения; это контракт порядка джобов T02/T04, но семантику начисления T06 надо зафиксировать до кодинга. Рекомендация: перевод следующей датой (лаг 15 дней) — иначе НС не имеет смысла как счёт

**Допущения:**
- Контракты соседей приняты по роадмапу: T03 отдаёт matching API (FIFO-потребление лотов, matched_bv = фактический BV потреблённых лотов по DEC-016, match_group_id для reversal-связки); T04 отдаёт half-month периоды (окна 1-15 / 16-EOM UTC, полуоткрытые) и close-pipeline с регистрацией шагов; T02 отдаёт субсчёт НС + bonus-posting API поверх механизма post()/assertBalanced()/alreadyPosted() существующего LedgerService; T05 отдаёт достигнутый ранг на момент времени; T01 отдаёт rate_bps и капы в USD-центах из PolicyVersion
- Капы в USD по курсу 468 делятся нацело (проверено): 234000/468=500 ... 18720000/468=40000, Pearl 11700000/468=25000 (DEC-039) — half-капы = ровно половина, дробных центов в конфиге нет
- Население бинара — все binary descendants включая spillover (решение владельца DEC-055); eligibility — достигнутый ранг >= CONSULTANT (ранг навсегда, DEC-020), у CLIENT лоты копятся, но не матчатся
- Порядок каскада DEC-053 реализуется разделением calculate/post: до влития T11 net_cents = after_cap_cents и posting идёт сразу при закрытии окна; T11 позже вставит scale-down между шагами без изменения схемы T06
- Округление — floor до цента на финальной проводке каждого бонуса (DEC-002), режим при необходимости конфигурируется в PolicyVersion (T01)
- Monthly-safety использует ранг текущего окна: ранги монотонны (навсегда), поэтому monthly cap не убывает внутри месяца и формула min(gross, half_cap, monthly_cap - использовано_в_месяце) корректна
- Единый feature flag mh_plan_v2 (сидируется более ранней V2-задачей) гейтит роуты T06; отдельного флага T06 не заводит
- CalculatorV2ServiceProvider уже существует к началу T06 (создаётся T01/T02); если нет — T06 создаёт его по рекомендации preflight с подключением одной строкой из основного провайдера
- V1 (Domain/BinaryBonusCalculator, CompensationEngine) не трогается вообще; advisory-lock активаций и cutover — зона T15
- Frontend-задач у T06 нет: админ-экраны — T13, miniapp — T14; cabinet/admin API T06 — их бэкенд-контракт
- Прод крошечный (5 участников без рангов) — ретро-пересчёт истории структурной премии не требуется, первое реальное начисление произойдёт после cutover T15

## T07 — Реферальная премия по тирам (10% L1 / 0-5-8% L2, на ОС сразу после оплаты)
Зависит от: T02, T05

**Таблицы:**
- v2_referral_rewards — id; order_id FK orders; source_member_id (покупатель); beneficiary_member_id (получатель); depth smallint (1|2); tier_snapshot enum start|business|elite|null (тир получателя на paid_at, из T05); rate_bps int (1000/500/800/0); base_bv_cents bigint (снапшот базы BV заказа); gross_cents bigint; net_cents bigint nullable (заполняет T11 после 60%-калибровки; NULL = не калибровано); status enum posted|zero_rate|blocked_elite|no_beneficiary; policy_version_id FK (T01, транзитивно); paid_at timestamp; ledger_idempotency_key string (v2:referral:order:{id}:d{depth}); explain json (входы формулы: тир, ставка, база, округление — DEC-054); reversed_at nullable + reversal_reason nullable (заполняет T12); timestamps. UNIQUE(order_id, depth); INDEX(beneficiary_member_id, paid_at); INDEX(paid_at) для пул-финализации T11
- feature_flags — существующая таблица, только seed-строка mh_v2_referral (disabled)

**Миграции (по порядку):**
- 2026_07_12_070100_create_v2_referral_rewards_table.php — слот T07 = 2026_07_12_0701xx-0709xx, не пересекается с другими задачами блока
- 2026_07_12_070200_seed_mh_v2_referral_feature_flag.php — сид флага mh_v2_referral в feature_flags со значением disabled (deny-by-default, включение — руками/T15)

**Backend:**
- НОВЫЙ ModelsV2/ReferralReward.php — Eloquent-модель v2_referral_rewards (casts на integer-центы, bps)
- НОВЫЙ ServicesV2/Referral/ReferralBonusService.php — ядро: onOrderPaid(Order $order): проверка feature-flag снаружи; guard referral_stop_at_elite (тир покупателя ДО заказа == ELITE из T05 tier-history → explain-запись blocked_elite, денег нет; crossing-заказ платится — DEC-011); resolveEligibleBv(Order): int — ШОВ базы BV (сейчас orders.total_usdt_cents, после T03 — его снапшот-поле); траверс sponsor_id глубиной 1-2 (Member::sponsor, стоп на NULL); для каждого получателя: tier = T05 TierService::tierAt(beneficiaryId, paid_at); rate из ReferralRateResolver; gross = intdiv(base_bv_cents * rate_bps, 10000) — округление вниз до цента (образец PPTX 90 979.2 → 90 979, DEC-002 финальная проводка); rate=0 или tier=null → строка v2_referral_rewards без ledger-проводки (explain only); иначе кредит ОС через контракт T02 AccountsServiceV2 (кредит-лот 1 год, idempotency key v2:referral:order:{id}:d{depth}) + строка v2_referral_rewards status=posted. Идемпотентность: UNIQUE(order_id,depth) + alreadyPosted-паттерн ledger — повтор webhook = no-op
- НОВЫЙ ServicesV2/Referral/ReferralRateResolver.php — матрица ставок {tier}×{depth} в bps и флаг referral_stop_at_elite из конфига PolicyVersion (T01, транзитивно через T02/T05) на дату paid_at; отсутствие ключей конфига = fail-fast исключение (не молчаливый 0)
- НОВЫЙ Http/Controllers/V2/ReferralRewardController.php — cabinet: список своих наград (пагинация, суммы в центах, explain-статусы); admin: список с фильтрами member/order/период (для T13)
- НОВЫЙ Routes/api/v2_referral.php — по образцу Routes/api/feature_flags.php: GET cabinet/v2/referral-rewards (middleware telegram.auth + feature.flag:mh_v2_referral), GET admin/v2/referral-rewards (web.admin + calculator.role:owner)
- ПРАВКА Routes/api.php — одна строка require __DIR__.'/api/v2_referral.php' в хвост (строки 257-263)
- ПРАВКА Services/OrderService.php::markPaid — единственный хук: после $this->activation->activate(...) добавить if ($this->featureFlags->isEnabled('mh_v2_referral')) { $this->referralBonus->onOrderPaid($order); } внутри той же транзакции; V1 recompute не трогается
- ПРАВКА Providers/CalculatorV2ServiceProvider.php — регистрация ReferralBonusService/ReferralRateResolver в маркер-блоке T07 (провайдер создаёт T01/T02 по рекомендации preflight; если к волне T07 его нет — T07 создаёт провайдер + одну строку подключения в CalculatorServiceProvider::register())
- КОНТРАКТ к T02 (не реализуем): AccountsServiceV2::creditOs(int $memberId, int $amountCents, string $sourceType, string $sourceRef, string $idempotencyKey) — двойная запись company_expense→member_os + кредит-лот с expires_at = paid_at + 1 год; точную сигнатуру зафиксировать на Гейте A
- КОНТРАКТ к T05 (не реализуем): TierService::tierAt(int $memberId, DateTimeInterface $at): ?string по tier-history; семантика crossing: тир вступает в силу ПОСЛЕ заказа, его повысившего (DEC-011) — т.е. tierAt(buyer, за миг до paid_at) для guard'а stop_at_elite
- МЕТОД для T12 (провенанс, не логика): все данные для точного сторно (rate_bps, base_bv_cents, tier_snapshot, ledger key) лежат в строке награды; reversal-проводки делает T12
- ТЕСТЫ: Tests/Feature/V2ReferralBonusTest.php, Tests/Unit/V2/ReferralRateResolverTest.php

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Services/OrderService.php — markPaid: T07 (хук реферальной), T03 (снапшот BV/PV), T12 (возвраты); правки сериализовать в merge-train
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php (и одна строка в CalculatorServiceProvider.php, если провайдер создаёт T07) — правят все V2-задачи; маркер-блоки по задачам
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна require-строка, конфликт с T02/T03/T05-T14 на соседних строках
- phpunit.xml — только если понадобится env-флаг для тестов (план: не трогать)

**Риски конфликтов:**
- T06 (структурная премия) может завести СВОЮ таблицу наград другой формы — T11 (60%-пул) потом агрегирует все бонусы; зафиксировать на Гейте A общий минимум колонок (gross/net/период/статус/reversed) по образу spec reward_calculation, чтобы T11 работал UNION'ом без миграций
- T03 меняет источник BV (раздельный снапшот BV/PV на OrderItem, DEC-003): если T03 мержится раньше — resolveEligibleBv читает его поле; позже — остаётся total_usdt_cents и T03 обязан сохранить обратную совместимость или прислать one-line PR в шов
- T02: сигнатура и семантика creditOs/кредит-лотов — T07 первый внешний потребитель; расхождение имени метода/ключа идемпотентности ломает компиляцию волны — контракт зафиксировать до старта кодинга
- T05: семантика tierAt для crossing-заказа (тир ПОСЛЕ повысившего заказа) критична для guard'а stop_at_elite; если T05 сделает тир «на момент включая заказ», ELITE-crossing потеряет премию вопреки DEC-011
- T11 будет ретро-корректировать уже зачисленные на ОС реферальные (пул считается месяцем, премия постится сразу): T07 обязан оставить net_cents nullable и не считать gross==net инвариантом
- Гонка создания CalculatorV2ServiceProvider между T01/T02/T07 — создаёт первая задача в merge-train, остальные аппендят маркер-блоки
- Timestamp-слоты миграций: T07 занимает 2026_07_12_0701xx-0709xx — оркестратору подтвердить карту слотов по всем задачам

**Тест-план:**
- ДЕНЬГИ (обязательные, Feature): happy-path цепочка A←B←C, C покупает: B получает L1 = 10% base_bv, A получает L2 по СВОЕМУ тиру; проверка точных сумм в центах, сбалансированности ledger-группы (Σdebit=Σcredit), создания ОС-лота с expires_at = paid_at + 1 год (через контракт T02)
- Матрица тиров L2: получатель START → строка zero_rate БЕЗ ledger-проводки; BUSINESS → 5%; ELITE → 8%; получатель без тира (tierAt=null) → поведение по решению Гейта A (вопрос 2), тест фиксирует выбранный вариант
- stop_at_elite: покупатель ELITE до заказа + флаг TRUE → нет наград, строки blocked_elite; флаг FALSE → награды есть; crossing-заказ (ELITE достигается этим заказом) → награды ЕСТЬ при любом значении флага (DEC-011)
- Идемпотентность (деньги): повторный markPaid/webhook-replay → ровно один набор проводок и строк (UNIQUE(order_id,depth) + alreadyPosted no-op); параллельный повтор под lockForUpdate
- Округление: base_bv×5% с дробными центами → floor до цента, rounding-дельта видна в explain (образец PPTX 90 979.2 → 90 979)
- Траверс: нет спонсора → ноль наград без ошибки; один уровень спонсора → только L1; самопокупка не начисляет покупателю
- Feature-flag OFF (дефолт) → onOrderPaid не вызывается, V1-контур байт-в-байт не меняется (регресс OrderActivationTest/AccrualLedgerTest зелёные)
- Fail-fast конфига: PolicyVersion без реферальных ставок → исключение, заказ НЕ проходит markPaid молча (деньги не теряются тихо)
- Negative по правам: cabinet-эндпоинт отдаёт только СВОИ награды (чужой member → пусто/403), без telegram.auth → 401; admin-эндпоинт для не-owner → 403; роуты за feature.flag:mh_v2_referral → 404/403 при выключенном флаге
- Unit: ReferralRateResolver — матрица из конфига, referral_stop_at_elite дефолт TRUE при отсутствии ключа, bps-арифметика на граничных суммах (1 цент, макс. тариф)

**Вопросы к Гейту A:**
- Гейт A (заложено роадмапом): referral_stop_at_elite на старте прода — оставить TRUE (покупки ELITE-покупателя реферальную НЕ генерят, как в спеке; скидки MH при этом нет вообще — ап-лайн ELITE-покупателя не получает ничего) или выключить (FALSE: реферальная платится со всех покупок, включая ELITE)? Дефолт в коде — TRUE.
- Получатель без тира (накопленный personal PV < 100, например только Bronze 90 PV по DEC-010): реферальную L1 10% платить всё равно или не платить вовсе (explain-запись no_tier без денег)? Таблица BR-TIER-001 начинается со START≥100 PV; рекомендация — не платить до достижения START, но это влияет на деньги текущих 5 участников.

**Допущения:**
- Ставки и флаг живут в конфиге PolicyVersion (T01): referral.rates_bps = {start:{1:1000,2:0}, business:{1:1000,2:500}, elite:{1:1000,2:800}}, referral.stop_at_elite = true; если сид T01 их не содержит — T07 добавляет ключи в сид-конфиг T01 согласованной правкой
- База BV = orders.total_usdt_cents (текущий снапшот на заказ) до прихода T03; интеграция с T03 — через единственный шов resolveEligibleBv
- Округление gross — усечение вниз до цента (intdiv по bps), в пользу компании, согласно примеру PPTX 90 979.2 → 90 979; DEC-002 направление явно не фиксирует
- Момент начисления — внутри транзакции markPaid сразу после активации; отдельного event-bus/outbox для T07 не строим (2 запроса на заказ, объём крошечный)
- T02 предоставляет идемпотентный метод кредита ОС с созданием лота (creditOs или эквивалент) и не требует от T07 знать внутренности лотов/проводок; T05 предоставляет tierAt по tier-history с семантикой «тир после crossing-заказа»
- Сторно реферальной — целиком скоуп T12; T07 лишь хранит полный провенанс (rate_bps, base, tier_snapshot, ledger key) и nullable reversed_at
- Неймспейс V2-моделей — Modules\Calculator\ModelsV2 (зеркально ServicesV2); финальную конвенцию нормализует оркестратор до старта волны
- 60%-калибровка не меняет момент постинга реферальной (роадмап: «на ОС сразу после оплаты»); ретро-корректировки периода — механика T11 через net_cents
- Подписки нет (DEC-004), юр-eligibility нет (DEC-005) — единственные условия получателя: существует, не терминирован; compliance-проверок в T07 нет
- Фронт (админ-страница наград и miniapp-история) — скоуп T13/T14; T07 отдаёт им готовые read-эндпоинты

## T08 — Лидерский бонус глубиной до 7 (CAL-LED-001): START 10% / BUSINESS 15% с L1, ELITE 20/10/5/3/1/1/1% по статусной глубине, rank-gap блок по DEC-030, база = фактически выплаченная структурная премия даунлайна (DEC-029), начисление на ОС в закрытие half-month
Зависит от: T06

**Таблицы:**
- v2_leadership_bonus_lines — единственная новая таблица (начисления + аудит исключений в одной): id; period_id (half-month период из T04); receiver_member_id, source_member_id (FK members); source_structure_bonus_id (FK на строку структурной премии T06 — контракт с T06); depth tinyint 1..7 (по реферальной sponsor-цепочке, БЕЗ компрессии); receiver_rank_key + receiver_tier (снапшот на период из T05, «ранг навсегда»); rate_bp int (ставка в базисных пунктах из PolicyVersion); base_cents bigint (net-сумма структурной премии источника ПОСЛЕ капов и 60%-калибровки — колонка-контракт T06/T11); amount_cents bigint (integer USD-центы); status enum(accrued, posted, excluded, reversed); exclusion_reason nullable enum(RANK_GAP_BLOCK, BELOW_MANAGER, RATE_ZERO, DEPTH_NOT_ALLOWED); blocking_member_id nullable (кто заблокировал ветвь — для explainability в T13); policy_version_id; ledger_tx_id nullable (UUID группы проводок); unique(source_structure_bonus_id, receiver_member_id) — ключ идемпотентности; индексы (receiver_member_id, period_id), (period_id, status)
- feature_flags — НЕ новая таблица, сид-строка v2_leadership (deny-by-default) через миграцию
- Проводки на ОС — через таблицы T02 (субсчета/кредит-лоты, срок 1 год) и ledger_entries; T08 своих денежных таблиц кроме строк начислений не создаёт

**Миграции (по порядку):**
- 2026_07_12_080100_create_v2_leadership_bonus_lines_table.php (слот timestamp 2026_07_12_08xxxx зарезервирован за T08 по карте preflight)
- 2026_07_12_080200_seed_v2_leadership_feature_flag.php (строка v2_leadership в feature_flags, выключено)

**Backend:**
- mh-calc-backend-main/Modules/Calculator/DomainV2/Bonus/LeadershipCalculator.php — ЧИСТЫЙ расчёт по псевдокоду CAL-LED-001: walk вверх по sponsor-цепочке от источника, depth++ всегда (без компрессии), eligibility rank>=MANAGER, ставка по tier получателя (START/BUSINESS только L1 10/15%; ELITE — глубина по рангу: Manager/Bronze 1, Silver 2, Gold 3, Platinum 4, Director 5, Pearl 6, Sapphire/Diamond/VP 7, ставки 20/10/5/3/1/1/1%), rank-gap блок: если на пути source..receiver (включая source) есть узел с ordinal >= receiver_ordinal + gap (конфигурируемо, дефолт 3 — пример Директора: Sapphire платится, Diamond+ блок) — исключение RANK_GAP_BLOCK; вход — DTO снапшотов, без Eloquent, null-safe до корня
- mh-calc-backend-main/Modules/Calculator/DomainV2/Bonus/LeadershipLine.php — DTO результата (начисление или исключение с причиной)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Bonus/LeadershipBaseSourceInterface.php — интерфейс базы: отдаёт по периоду строки структурной премии T06 (id, member_id, net_amount_cents); ЕДИНСТВЕННАЯ точка стыка с T06/T11 — когда T11 внесёт 60%-калибровку в net-колонку, T08 не меняется
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Bonus/StructureBonusBaseSource.php — адаптер интерфейса поверх таблицы/репозитория T06
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Bonus/LeadershipBonusService.php — оркестратор runForPeriod(periodId): guard флага и статуса периода, загрузка sponsor-цепочек и rank/tier-снапшотов (T05), вызов калькулятора, upsert строк по unique-ключу, проводка amount_cents на субсчёт ОС получателя через AccountsService T02 (кредит-лот 1 год) с idempotency-ключом v2:leadership:{period_id}:{source_structure_bonus_id}:{receiver_id}, всё в DB::transaction; повторный запуск — no-op
- mh-calc-backend-main/Modules/Calculator/Console/V2/LeadershipRunCommand.php — calculator:v2:leadership-run {period} для ручного прогона/backfill, идемпотентна, withoutOverlapping; в штатном режиме шаг вызывается из V2 close-пайплайна half-month (T04/T06) ПОСЛЕ шагов caps и 60%-пула (порядок DEC-053)
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2/LeadershipBonusController.php — cabinet: мои лидерские начисления по периодам (только свои); admin (owner): отчёт по периоду — начисления, суммы, исключения с причинами и blocking_member (кормит T13)
- mh-calc-backend-main/Modules/Calculator/Routes/api/v2_leadership.php — по образцу feature_flags.php: cabinet-группа telegram.auth + feature.flag:v2_leadership, admin-группа web.admin + calculator.role:owner; плюс ОДНА строка require в Routes/api.php
- mh-calc-backend-main/Modules/Calculator/Models/V2/LeadershipBonusLine.php — Eloquent-модель
- Регистрация: DI-биндинг LeadershipBaseSourceInterface, сервис и команда — блоком-маркером T08 в CalculatorV2ServiceProvider (если провайдер ещё не создан предыдущими волнами — T08 создаёт его и подключает одной строкой из CalculatorServiceProvider::register())
- Tests: Tests/Unit/V2/LeadershipCalculatorTest.php, Tests/Feature/V2/LeadershipBonusServiceTest.php, Tests/Feature/V2/LeadershipApiTest.php

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require (конфликт с T02..T14, тривиальный)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php (или CalculatorServiceProvider.php, если V2-провайдера ещё нет) — горячий файл всех задач блока; писать только маркер-блоком T08
- Таблица/модель структурной премии T06 — read-only зависимость, но ЖЁСТКИЙ контракт колонок (id, member_id, period_id, net_amount_cents, status)
- Реестр шагов V2 close-пайплайна half-month (артефакт T04/T06) — T08 добавляет шаг leadership строго после шага 60%-пула
- mh-calc-backend-main/phpunit.xml — только если понадобится env-флаг для тестов (стремиться обойтись без правок)

**Риски конфликтов:**
- CalculatorV2ServiceProvider — самый острый хотспот блока (T02/T04/T06/T07/T09/T10 тоже регистрируются); митигировать маркер-блоками '>>> T08 leadership' и append-only
- Контракт с T06: имя и семантика net-колонки строки структурной премии (gross_cents/capped_cents/net_amount_cents + enum статусов CALCULATED→CAPPED→CALIBRATED→POSTED по 05_Data_Model 4.9) должны быть зафиксированы ДО кодинга T08 — иначе переделка адаптера; зафиксировать на merge-train до старта волны
- Контракт с T11 (идёт позже): T11 обязан записывать калиброванную сумму в ту же net-колонку ДО шага leadership в пайплайне (порядок DEC-053 raw→caps→60%-пул→база лидерского→posting); T08 кодирует позицию шага, T11 не должен её двигать
- T12 (сторно) будет реверсить лидерские строки каскадом от structure-reversal — T08 обязан сохранить FK source_structure_bonus_id, стабильный unique-ключ и статус reversed (не удалять строки)
- T13 читает v2_leadership_bonus_lines для отчёта — имена колонок status/exclusion_reason/blocking_member_id считать публичным контрактом
- Слот миграций 2026_07_12_08xxxx — коллизия timestamp с соседями исключается назначенными диапазонами
- Формулировка роадмапа «компрессия минус два статуса» противоречит триажу DEC-030 «без compression, блок партнёра и subtree» — реализуем по триажу (блок, depth не сжимается); риск разночтения с планировщиком T16 (документ политики)

**Тест-план:**
- ДЕНЬГИ (обязательные) Unit LeadershipCalculator: START/BUSINESS-получатель — только L1, 10%/15%, L2+ не платится; ELITE — точные ставки 20/10/5/3/1/1/1% по depth; глубина по рангу (Manager 1 … Pearl 6, Sapphire/Diamond/VP 7), за пределом — DEPTH_NOT_ALLOWED; получатель ниже Manager — пропуск с BELOW_MANAGER, depth всё равно инкрементится (без компрессии); цепочка до корня — null-safe
- ДЕНЬГИ rank-gap (пример Директора из спеки): Director-получатель + Sapphire-источник = платится; + Diamond-источник = RANK_GAP_BLOCK; блокирующий узел ПОСЕРЕДИНЕ пути (не сам источник) тоже блокирует (subtree-блок); порог gap конфигурируем — тест с gap=2 меняет исход
- ДЕНЬГИ база DEC-029: amount = net_amount_cents строки T06 (после капов), НЕ gross; источник с premium, срезанной капом в 0 → строк нет; при подмене net (эмуляция 60%-калибровки T11) сумма лидерского меняется пропорционально без изменения кода T08
- ДЕНЬГИ golden-тест: пересчёт примера S28 Director/ELITE (L1 20% и L2 10%) в USD-центах против ручных чисел
- ДЕНЬГИ идемпотентность: повторный runForPeriod и повторный запуск команды — ноль новых строк и ноль новых ledger-проводок (unique-ключ + alreadyPosted); проводка на ОС создаёт кредит-лот со сроком 1 год; Σдебет=Σкредит (assertBalanced)
- Округление: полбп-случаи по rounding-правилу PolicyVersion, инвариант — суммы только integer-центы
- Guard периодов: запуск на FINALIZED-периоде без rerun-режима — отказ; статусы строк проходят accrued→posted
- NEGATIVE API: cabinet без telegram.auth — 401; партнёр видит только свои строки (чужой member_id — пусто/403); admin-роуты под web.admin+calculator.role:owner — не-owner 403; флаг v2_leadership выключен — cabinet 403/404
- Perf-smoke: период с ~1000 строк структурной премии и цепочками глубиной 7 — прогон в один проход без N+1 (предзагрузка sponsor-цепочек)

**Вопросы к Гейту A:**
- 60%-числитель vs лидерский (стык DEC-014/029/053): порядок DEC-053 ставит расчёт лидерского ПОСЛЕ 60%-пула, значит лидерский того же периода сам НЕ входит в числитель калибровки этого периода (иначе цикл, о котором предупреждает 03_Calculation_Engine §11). Но решение DEC-014 говорит «включены все бонусы». Подтвердите: лидерский исключается из 60%-базы своего периода (только отражается в отчёте), без итеративного пересчёта?
- DEC-030: подтвердите финальную семантику — БЛОК без компрессии (заблокированный партнёр и весь его subtree не порождают выплату получателю, уровень не «подтягивается» выше), порог = разница ordinal >= 3 (Director: Sapphire платится, Diamond+ блок), порог — параметр PolicyVersion. Формулировка роадмапа «компрессия» трактуется именно так?
- Rank-gap проверять относительно ранга источника И промежуточных узлов пути (subtree-блок, как в триаже) — или только самого источника премии? Планируем «весь путь включая источник» (совпадает с V1 hasHigherRankInChain и триажем); нужно явное «ок», это деньги.

**Допущения:**
- Контракт T06 (по роадмапу/спеке, не перепланируя их): строки структурной премии per source per half-month с колонкой финальной net-суммы после капов (рабочее имя net_amount_cents); до появления T11 net = post-cap (pool factor 1.0)
- Ставки, глубины по рангу, порог rank-gap, rounding-правило — из PolicyVersion T01 (лидерская секция уже в скоупе T01 по роадмапу); T08 своих конфиг-таблиц не заводит
- Ранг и тир получателя — снапшот T05 на период; «ранг навсегда» (DEC-020) делает ранг монотонным, берём состояние на конец периода
- sponsor_id неизменяем после активации (реферальная цепочка стабильна) → живая цепочка эквивалентна снапшоту периода; если T04 даст tree-revision снапшоты — переключимся на них
- Начисление на ОС сразу в закрытие half-month (Destination OS по CAL-LED-001), БЕЗ этапа НС и без перевода 1/16 — в отличие от структурной премии
- Consultant/Trainee лидерский не получают (rank>=MANAGER); депозит через AccountsService T02 доступен транзитивно (T08→T06→T02)
- Начисления и исключения — одна таблица со status/exclusion_reason (аудит recordExcluded по спеке), отдельная exclusions-таблица не нужна
- V2-неймспейсы DomainV2/ServicesV2 и CalculatorV2ServiceProvider создаются ранними задачами волны; если T08 стартует первым в своей волне — создаёт провайдер сам по рамке preflight
- T08 чисто бэкендовый: UI по лидерскому — T13 (админ-отчёт) и T14 (miniapp), фронт-массивы пустые
- Ставки храним в basis points (10%=1000bp), деньги — integer USD-центы; курс 468 KZT=1 USD уже учтён на уровне конфига T01

## T09 — Глобальный бонус: месячные пулы Director..VP, доли по PV реферального дерева, квартальная выплата на ОС
Зависит от: T04 (периоды month/quarter, статусы open/closed, оркестрация month-close/quarter-close, идемпотентные джобы), T05 (achieved rank per member на дату — «ранг навсегда» DEC-020; таблица достижений рангов V2), транзитивно T01 (PolicyVersion: секция global в конфиге — ставки пулов, base PV, max_shares, cap 25%), транзитивно T02 (AccountsV2/LedgerV2: кредит на ОС с созданием кредит-лота 1 год), транзитивно T03 (BV/PV снапшот на заказ — источник месячного global BV и PV реф.дерева)

**Таблицы:**
- v2_global_bonus_months: id, month_period_id (FK периода T04, unique), policy_version_id, global_bv_cents bigint, status enum(draft,final), computed_at, finalized_at, meta json — immutable-снапшот месяца (DEC-036)
- v2_global_bonus_pools: id, global_bonus_month_id FK, pool_rank enum(director,pearl,sapphire,diamond,vp), rate_bps int (100/75/50/50/25), pool_amount_cents bigint, total_shares int, allocated_cents bigint, unallocated_cents bigint, unallocated_reason enum(null,cap_remainder,empty_pool,rounding); unique(month_id, pool_rank)
- v2_global_bonus_qualifications: id, global_bonus_month_id FK, member_id, achieved_rank, referral_tree_pv decimal(14,2), base_pv decimal(14,2), max_shares int (снапшот конфига=2), shares int (0..max), calculated_at; unique(month_id, member_id) — КОНТРАКТ для T10 (VP этапы 2-3 = первые две записи с shares>=1 при ранге VP, DEC-042)
- v2_global_bonus_allocations: id, global_bonus_month_id FK, pool_id FK, member_id nullable (NULL = компания/UNALLOCATED, DEC-034), kind enum(member,unallocated), shares int, raw_cents bigint, capped_cents bigint (после 25%-cap + largest remainder), final_cents bigint (default = capped; T11 60%-калибровка перезаписывает ДО квартальной выплаты), status enum(accrued,paid,reversed); unique(pool_id, member_id)
- v2_global_bonus_payouts: id, quarter_period_id FK, member_id, amount_cents bigint (сумма final_cents 3 месяцев), idempotency_key string unique (v2:glb:q:{quarterId}:m:{memberId}), posted_at, status enum(posted,reversed); unique(quarter_period_id, member_id)

**Миграции (по порядку):**
- Слот timestamp задачи: 2026_07_12_0900xx (не пересекается с T01..T08, T10+; порядок внутри задачи строгий)
- 2026_07_12_090000_create_v2_global_bonus_months_table.php
- 2026_07_12_090010_create_v2_global_bonus_pools_table.php
- 2026_07_12_090020_create_v2_global_bonus_qualifications_table.php
- 2026_07_12_090030_create_v2_global_bonus_allocations_table.php
- 2026_07_12_090040_create_v2_global_bonus_payouts_table.php
- 2026_07_12_090050_seed_feature_flag_mh_v2_global_bonus.php (deny-by-default, по образцу feature_flags C3)

**Backend:**
- Modules/Calculator/Models/V2/GlobalBonusMonth.php, GlobalBonusPool.php, GlobalBonusQualification.php, GlobalBonusAllocation.php, GlobalBonusPayout.php — Eloquent-модели новых таблиц
- Modules/Calculator/ServicesV2/GlobalBonus/GlobalBonusConfig.php — типизированный ридер секции global из PolicyVersion (T01): pool_rate_bps по рангу, one_share_pv_min (base), max_shares (default 2), member_pool_cap_bps (2500), inherits_lower_pools (true), quarter_mode (calendar), include_personal_pv (bool)
- Modules/Calculator/ServicesV2/GlobalBonus/ReferralTreePvMonthlyService.php — месячный PV реферального дерева партнёра: рекурсивный обход sponsor_id (Member.sponsor_id) по PAID-заказам месяца из PV-снапшотов T03; если к моменту реализации в ServicesV2/Tree появится общий обход (T05/T07/T08) — использовать его
- Modules/Calculator/ServicesV2/GlobalBonus/GlobalBonusMonthlyService.php — ядро: (1) global BV месяца = Σ BV-снапшотов PAID-заказов минус reversals; (2) квалификации: для member с achieved_rank>=Director shares=min(floor(PV/base(rank)), max_shares); (3) наследование: shares участника добавляются в его пул и ВСЕ нижние пулы (Sapphire с 2 долями → Sapphire+Pearl+Director); (4) аллокация per pool: знаменатель=Σдолей (DEC-033), largest-remainder в центах ДО капа (DEC-035), cap 25% пула (DEC-034), остаток/пустой пул → allocation kind=unallocated; (5) финализация месяца → status=final, повторный запуск = no-op; финальный месяц пересчёту запрещён
- Modules/Calculator/ServicesV2/GlobalBonus/GlobalBonusQuarterlyPayoutService.php — по закрытому кварталу (T04): проверка что все 3 месяца final; Σ final_cents по участнику; проводка на ОС через AccountsV2/LedgerV2 (T02) с созданием кредит-лота 1 год, идемпотентный ключ v2:glb:q:{quarterId}:m:{memberId} (паттерн alreadyPosted из LedgerService.php:255); allocations → status=paid; нулевые суммы не постятся; UNALLOCATED — только снапшот, ledger-проводка не нужна (деньги компанию не покидали)
- Modules/Calculator/Console/V2/GlobalBonusAllocateMonthCommand.php — calculator:v2:global-allocate {month?}, идемпотентная, withoutOverlapping; вызывается из month-close пайплайна T04 (шаг global-bonus-month по 06_API §batch: после month-close, ДО 60%-калибровки T11)
- Modules/Calculator/Console/V2/GlobalBonusQuarterPayoutCommand.php — calculator:v2:global-payout {quarter?}; вызывается из quarter-close T04 (роадмап T04 явно включает «квартальная выплата глобального» в расписание — T09 поставляет команду, T04 — слот расписания)
- Modules/Calculator/Http/Controllers/V2/GlobalBonusAdminController.php — read-only отчёты: месяц (пулы/квалификации/аллокации/unallocated), квартал (payout-предпросмотр и факт), ручной перезапуск draft-месяца; группа admin (web.admin + calculator.role:owner) + feature.flag:mh_v2_global_bonus
- Modules/Calculator/Routes/api/v2_global_bonus.php — роут-файл фичи + ОДНА строка require в Routes/api.php (паттерн C1-C7)
- Регистрация сервисов/команды — блок T09 в CalculatorV2ServiceProvider (создаёт T02/T04; T09 только добавляет свой маркер-блок)

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php (создаётся T02/T04; T09 дописывает DI + команду — главный хотспот блока)
- mh-calc-backend-main/Modules/Calculator/Routes/api.php (одна строка require — конфликт со всеми T02..T14, тривиальный merge)
- Схема конфига PolicyVersion (T01): T09 добавляет/читает секцию global (по 07_Rules_Config.example.yaml: total 3%, per-rank pool_rate/one_share_pv_min/two_share_pv_min → в V2: base+max_shares, cap 25%, inherits_lower=true) — имена ключей согласовать с T01 до кода
- Контракт month-close/quarter-close пайплайна T04 (реестр шагов/хуков): порядок month-close → T09 allocate → T11 калибровка (пишет final_cents) → финализация; quarter-close → T09 payout
- v2_global_bonus_qualifications — читается T10 (VP этапы 2-3) и потенциально T12 (reversal) — схема = контракт
- AccountsV2/LedgerV2 API (T02): сигнатура «кредит ОС + лот 1 год + idempotency key» — T09 потребитель

**Риски конфликтов:**
- CalculatorV2ServiceProvider: T02/T04/T06-T10 правят одновременно — сериализовать через merge-train, T09 добавляет только свой маркер-блок '>>> T09 global bonus'
- Порядок шагов month-close с T11: T11 (60%-пул, DEC-014 «все бонусы» + DEC-053) должен скейлить месячные аллокации глобального ЧЕРЕЗ final_cents ДО финализации месяца и до квартальной выплаты; если T11 запланирует свой собственный стор скейлинга — двойной учёт; колонка final_cents в v2_global_bonus_allocations = единственная точка интеграции, донести до планировщика T11
- Дублирование обхода реферального дерева: T05 (квалификации по ветвям), T07 (уровни 1-2), T08 (глубина 7), T09 (весь subtree PV) — риск 3-4 независимых реализаций; предложить общий ServicesV2/Tree/ReferralTreeQuery, но T09 не блокируется — своя реализация за интерфейсом
- T10 завязан на v2_global_bonus_qualifications (FIRST/SECOND_VP_GLOBAL_BONUS_QUALIFICATION): менять схему после старта T10 нельзя без синка
- T12 (сторно): reversal заказа в открытом месяце меняет global BV и PV → draft-месяц пересчитываем, final — только корректирующими проводками; поле status в allocations/payouts заложено, сами корректировки — скоуп T12
- Timestamp-коллизии миграций: T09 держит слот 2026_07_12_0900xx
- Routes/api.php: конфликт на одной строке require со всеми задачами — тривиален, но попадает в каждый merge

**Тест-план:**
- [деньги, обяз.] Golden case DEC-031/038 в USD: global BV 5 000 000 USD (500 000 000 центов) → 3% = 150 000 USD; пулы 50 000/37 500/25 000/25 000/12 500 USD; проверка точного соответствия в центах
- [деньги, обяз.] Доли: Director PV 100 000→1, 199 999.99→1, 200 000→2, 250 000→2 (max=2), 99 999→0; конфиг max_shares=3 → floor работает выше 2; base по рангам Pearl 400k / Sapphire 1M / Diamond 3M / VP 6M
- [деньги, обяз.] Наследование пулов: Sapphire с 2 долями участвует 2 долями в Sapphire+Pearl+Director, НЕ в Diamond/VP; Director участвует только в Director
- [деньги, обяз.] Знаменатель = сумма долей (DEC-033): 1 участник с 2 долями + 1 с 1 долей → unit = pool/3, не pool/2
- [деньги, обяз.] Округление largest remainder ДО капа (DEC-035): пул 101 цент / 3 доли → суммы аллокаций + unallocated == pool_amount_cents ТОЧНО; инвариант Σ(capped)+unallocated==pool на property-тесте со случайными долями
- [деньги, обяз.] Cap 25%: единственный участник пула с 2 долями → получает 25% пула, 75% → kind=unallocated, reason=cap_remainder; никакого перераспределения другим (DEC-034)
- [деньги, обяз.] Пустой пул: нет квалифицированных VP → 100% VP-пула unallocated, reason=empty_pool
- [деньги, обяз.] Квартальная выплата: 3 финальных месяца с final_cents 100/200/0 → одна проводка 300 на ОС; кредит-лот со сроком 1 год создан (интерфейс T02); double-run команды → ровно одна ledger-группа (idempotency key)
- [деньги, обяз.] final_cents ≠ capped_cents (эмуляция T11 scale-down 0.8) → квартальная выплата берёт final; месяц draft → выплата отказ
- [negative] Ранг < Director с PV 10M → 0 квалификаций; ранг достигнут ПОСЛЕ конца месяца → не участвует в этом месяце; ранг достигнут давно, PV месяца 0 → квалификация с shares=0, аллокаций нет
- [negative] Квартал не закрыт / не все 3 месяца final → команда выплат отказывает с понятной ошибкой, ничего не постит
- [идемпотентность] Повторный calculator:v2:global-allocate по final-месяцу → no-op; по draft → полный детерминированный пересчёт (те же входы → байт-в-байт те же суммы)
- [права/negative] Админ-роуты: без web.admin → 401; роль не owner → 403; feature flag mh_v2_global_bonus OFF → 404/403 и джобы no-op
- [integration] Сквозной: заказы 3 месяцев (PV/BV снапшоты) → месячные аллокации → закрытие квартала → баланс ОС участника и company_commission_expense сходятся по double-entry (assertBalanced)

**Вопросы к Гейту A:**
- Входит ли ЛИЧНЫЙ месячный PV партнёра в «PV реферального дерева» для расчёта долей, или только PV даунлайна по sponsor-дереву? (Спека говорит referral-tree PV без уточнения; предлагаю default: включать личный PV, конфиг-флаг include_personal_pv=true)
- База global BV месяца: ВСЕ оплаченные заказы компании (включая покупки лидов/клиентов без ранга и собственные заказы директоров) минус reversals? (Спека: eligible_company_bv без определения eligible; предлагаю default: все PAID минус reversed)
- Квартальное начисление на ОС выполняется автоматически при закрытии квартала (сам вывод денег — как весь контур, вручную), или начисление тоже требует ручного подтверждения админа перед проводкой? (Предлагаю: автоматическая проводка на ОС, вывод вручную)

**Допущения:**
- Пороговые значения долей задаются как base PV на ранг + max_shares (конфигурируемо, старт 2 по DEC-032): shares=min(floor(PV/base), max) — эквивалентно таблице спеки 1/2 доли; хранится в PolicyVersion (T01) в секции global
- Наследование нижних пулов включено (07_Rules_Config: higher_status_inherits_lower_status_pools=true, текст 03_Calculation_Engine §12) — доли собственного статуса дублируются во все нижние пулы
- Eligibility = достигнутый ранг >= Director на конец месяца («ранг навсегда», DEC-020); проверка legacy cash bonus снята (DEC-037, IZIGO_CONTEXT); подписка не вводится (решение владельца DEC-004) — условия активности по подписке отсутствуют
- Квартал — календарный, конфигурируемый fiscal (DEC-036); месячный снапшот immutable после финализации; одна квартальная проводка на ОС на участника
- 60%-калибровка (T11, DEC-014 «все бонусы») применяется к МЕСЯЧНЫМ аллокациям глобального в составе месячного пула через колонку final_cents до финализации месяца (порядок DEC-053: raw → индивид. капы(25%) → 60%-пул → posting); квартальная выплата суммирует final_cents
- UNALLOCATED (DEC-034) фиксируется строками снапшота (member_id NULL), ledger-проводка не создаётся — деньги компанию не покидали; код причины cap_remainder/empty_pool сохраняется для отчёта админу
- Контракт T04: существуют периоды month/quarter с id и статусами open/closed и пайплайн закрытия, куда T09 регистрирует шаги; контракт T02: метод кредитования ОС с созданием кредит-лота (1 год) и idempotency key; контракт T05: запрос достигнутого ранга участника на дату; контракт T03: BV/PV снапшоты PAID-заказов с датой оплаты — при расхождении фактических сигнатур адаптируется тонкий слой T09, схема таблиц не меняется
- Ставки пулов храню в basis points (100/75/50/50/25 bps), деньги — integer USD-центы, PV — decimal; курс 468 KZT=1 USD уже применён в конфиге T01, T09 KZT не видит
- Реферальное дерево = цепочки Member.sponsor_id (бинарный path/ltree НЕ используется для глобального бонуса)
- Прод крошечный (5 участников без рангов) — производительность обхода дерева не критична для v1 реализации; рекурсивный CTE достаточен

## T10 — Квалификационные награды USD (единоразовые награды за статусы Manager..VP на Бонусный счёт, ручная выплата)
Зависит от: T02, T05

**Таблицы:**
- v2_award_entitlements: id; member_id (FK members); award_code (string: MANAGER|BRONZE_MANAGER|SILVER_MANAGER|GOLD_MANAGER|PLATINUM_MANAGER|DIRECTOR|PEARL_DIRECTOR|SAPPHIRE_DIRECTOR|DIAMOND_DIRECTOR|VICE_PRESIDENT); stage_no (smallint, default 1; для VP 1..3); amount_cents (integer, USD-центы, снапшот из PolicyVersion на момент гранта: 10000/20000/30000/50000/150000/250000/2000000/3500000/5300000/3x5000000); policy_version_id (nullable FK, provenance T01); trigger_type (enum: rank_achieved | global_qualification); trigger_ref (string: id rank-события T05 либо ключ месяца квалификации глобального бонуса YYYY-MM от T09); status (enum: granted | on_hold | paid_out | forfeited; default granted); granted_at; posted_at (момент проводки на БС); paid_at nullable; paid_by_admin_id nullable; note text nullable; meta json nullable; created_at/updated_at; UNIQUE(member_id, award_code, stage_no) — идемпотентность DEC-040/BR-AWD-002; INDEX(status), INDEX(member_id)
- Ledger: НОВЫХ таблиц проводок НЕТ — используются ledger_entries + БС-субсчёт/лоты из T02; T10 добавляет только константу счёта расхода company_award_expense (если T02 её не даёт) и source_type='award' в существующие проводки

**Миграции (по порядку):**
- 2026_07_12_100000_create_v2_award_entitlements_table.php — таблица entitlement'ов с unique(member_id, award_code, stage_no); слот таймстампов T10 = 2026_07_12_1000xx (не пересекается с соседями при назначении диапазонов по задачам)
- 2026_07_12_100100_seed_v2_awards_feature_flag.php — сид флага mh_v2_awards в feature_flags (deny-by-default, OFF), по паттерну C3

**Backend:**
- mh-calc-backend-main/Modules/Calculator/Models/V2/AwardEntitlement.php — Eloquent-модель (casts: amount_cents int, meta array; константы статусов и award_code, карта ordinal рангов не здесь — берётся из каталога статусов T05)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Awards/QualificationAwardService.php — ядро: (1) onRankAchieved(memberId, fromRankCode, toRankCode, achievedAt, policyVersionId) — вычисляет ВСЕ newly crossed ступени по ordinal-лестнице T05 (DEC-040), на КАЖДУЮ создаёт entitlement + ОТДЕЛЬНУЮ ledger-проводку на БС через API T02 (idempotency key вида v2award:{member}:{code}:{stage}), amount из PolicyVersion на дату события; entitlement+проводка в одной DB::transaction; (2) onGlobalQualificationCompleted(memberId, monthKey) — только если текущий достигнутый ранг == VICE_PRESIDENT: первая distinct месячная квалификация -> stage 2, вторая -> stage 3 (DEC-042 спека A, unique(member,VP,stage) гасит повторы, повтор того же monthKey — no-op); (3) markPaid(id, adminId, note) — ручной payout-контур: проводка БС -> company_payouts_paid через T02, статус paid_out, запись в admin_audit_log; (4) hold(id)/release(id)/forfeit(id, reason) — ручные решения админа (DEC-041/043), forfeit только для непроведённых выплат, начисление НЕ удаляется — только статус+аудит
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Awards/Listeners/GrantAwardsOnRankAchievedV2.php — подписчик на rank-событие T05 (контракт: событие/hook T05 обязано нести ПРЕДЫДУЩИЙ и НОВЫЙ ранг + дату + policy_version; аналог V1 IRankListener::onNewRank); регистрируется в CalculatorV2ServiceProvider
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Awards/Contracts/GlobalQualificationAwardHook.php — интерфейс-контракт для T09: метод onGlobalQualificationCompleted(int memberId, string monthKey); T09 вызывает его при фиксации месячной квалификации глобального бонуса (событие mh.global.qualification_completed по 06_API спеки); T10 публикует интерфейс, T9 — вызов; зафиксировать сигнатуру на Гейте A
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2/AwardsController.php — cabinet: GET свои награды (id, code, stage, amount_cents, status, granted_at/paid_at); admin (owner-only): GET очередь ?status=, POST {id}/mark-paid, POST {id}/hold, POST {id}/release, POST {id}/forfeit; admin-эндпоинты — данные для страницы «очередь наград» T13
- mh-calc-backend-main/Modules/Calculator/Routes/api/v2_awards.php — свой роут-файл по образцу feature_flags.php: группа cabinet (telegram.auth + feature.flag:mh_v2_awards), группа admin (web.admin + calculator.role:owner); + ОДНА строка require в Routes/api.php
- Регистрация: биндинг QualificationAwardService и подписка листенера — в CalculatorV2ServiceProvider (общий V2-провайдер блока; если к старту T10 его ещё нет — создать по рекомендации preflight и подключить одной строкой из CalculatorServiceProvider::register()); НЕТ scheduled-джобов и консольных команд в T10 (event-driven; expiry БС-лотов — зона T02)

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require (правят T02,T03,T05-T14)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php / CalculatorV2ServiceProvider.php — регистрация сервиса и листенера (горячий файл всего блока; писать только в V2-провайдер блочным маркером T10)
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — коллизии таймстампов; T10 занимает слот 2026_07_12_1000xx
- Таблица feature_flags (сид mh_v2_awards) — конфликтов по строкам нет, но конвенцию имён V2-флагов согласовать с T13/T15
- ledger_entries: новые значения account_type/source_type — координация словаря счетов с T02 (T10 не меняет схему)

**Риски конфликтов:**
- T05 (контракт события ранга): для DEC-040 «все пройденные ступени» событие T05 ОБЯЗАНО нести предыдущий и новый ранг (или список newly crossed); если T05 отдаст только новый ранг — детект скачка сломается; зафиксировать payload на Гейте A
- T09 (VP stages 2-3): T10 публикует интерфейс GlobalQualificationAwardHook, T09 обязан вызвать его идемпотентно один раз на (member, месяц) при квалификации глобального бонуса; T09 идёт параллельно (зависит от T04/T05, не от T10) — риск рассинхрона сигнатуры/семантики «distinct квалификация»; интеграционный вызов появится только после мерджа обеих задач
- T02 (БС-субсчёт): T10 не пишет legs напрямую — нужен API T02 «начислить на БС» (создание лота) и «выплатить с БС» (БС -> company_payouts_paid) с внешним idempotency key; если T02 даст только начисление без выплатного метода — markPaid блокируется; согласовать оба метода
- T12 (сторно): DEC-027/DEC-020 — достигнутые награды НЕ отзываются при возвратах («ранг навсегда»); T12 не должен генерировать reversal по source_type='award'; зафиксировать в контракте T12 исключение award-проводок
- T13/T14: форма ответа admin-очереди и cabinet-эндпоинта — контракт для страницц «очередь наград» (T13) и «награды» (T14); заморозить JSON-схему в этом плане
- T11 (60%-пул): по графу T11 не зависит от T10 — награды предположительно ВНЕ базы калибровки; если владелец решит включить, появится зависимость T11->T10 и перенос момента проводки на закрытие периода (см. вопрос)
- Горячие файлы api.php и V2-провайдер — конфликты с T06-T09 в merge-train; изменения строго append-only с маркерами

**Тест-план:**
- [деньги, обяз.] Unit: скачок через ранги — CONSULTANT -> GOLD_MANAGER одним событием даёт 4 entitlement'а (Manager 10000c, Bronze 20000c, Silver 30000c, Gold 50000c), каждый со СВОЕЙ отдельной сбалансированной ledger-группой (DEC-040)
- [деньги, обяз.] Unit: суммы всех 10 наград читаются из PolicyVersion и совпадают с решением владельца (100/200/300/500/1500/2500/20000/35000/53000/50000x3 USD в центах); amount_cents снапшотится в entitlement
- [деньги, обяз.] Feature: идемпотентность — повторная доставка того же rank-события не создаёт дублей (unique(member,award_code,stage_no) + ledger idempotency key); конкурентный повтор -> ровно одна проводка
- [деньги, обяз.] Feature: VP-этапы — stage 1 при достижении VP; stage 2 при первой месячной квалификации глобального в ранге VP; stage 3 при второй в ДРУГОМ месяце; тот же месяц повторно -> no-op; квалификация ДО достижения VP -> не считается; третья квалификация -> ничего (нет stage 4)
- [деньги, обяз.] Feature: markPaid — одна проводка БС -> company_payouts_paid ровно на amount_cents, повторный markPaid -> no-op/409; markPaid по on_hold/forfeited -> отказ; баланс БС и company-счетов сходится (assertBalanced)
- [negative, обяз.] Права: cabinet-эндпоинт отдаёт только СВОИ награды; admin-действия без calculator.role:owner -> 403; без web.admin -> 401; feature-flag mh_v2_awards OFF -> роуты закрыты
- [negative] forfeit по уже paid_out -> отказ; hold -> markPaid заблокирован до release; каждое admin-действие оставляет запись в admin_audit_log
- [обяз.] Сторно-безопасность: нет кодового пути, создающего отрицательную/reversal-проводку по source_type='award' (DEC-027, ранг навсегда) — статический тест на API сервиса
- Feature: события ранга при выключенном V2-движке (флаг OFF) не создают entitlement'ов — гейт на входе в листенер

**Вопросы к Гейту A:**
- Годичный expiry БС-лотов (BR-ACC-003) применять ли к award-кредитам? Если да — невыплаченная вручную награда (напр. VP 150000 USD) сгорит через год ожидания ручной выплаты. Предлагаемый дефолт: award-лоты на БС БЕЗ auto-expiry (expires_at = null в лотах T02), сгорание только ручным forfeit админа
- Входят ли единоразовые квалификационные награды в базу «все бонусы» 60%-калибровки (DEC-014)? Граф роадмапа говорит НЕТ (T11 не зависит от T10, награды event-time вне периода) — но формулировка владельца «включены все бонусы» допускает иное прочтение; крупная награда (Pearl+) способна съесть весь 60%-пул периода. Предлагаемый дефолт: НЕ включать (ручной гейт выплаты = контроль компании)

**Допущения:**
- Суммы наград берутся из конфига PolicyVersion (T01, транзитивно через T02) на дату события ранга и снапшотятся в entitlement; хардкода сумм в коде нет
- Все награды, включая Pearl/Sapphire/Diamond/VP, зачисляются на БС деньгами USD (решение владельца после Гейта 0); допсоглашения/in-kind — вне системы, отдельного флага «addendum signed» в движке нет; ручной гейт = markPaid админом (DEC-041/043)
- Entitlement создаётся и проводится на БС автоматически в момент rank-события (без pre-approval); ручное — только выплата/hold/forfeit, как весь payout-контур IziGo
- DEC-040: при скачке платятся ВСЕ пересечённые ступени, отдельная ledger-запись на каждую; лестница ordinal-ов — из каталога 12 статусов T05
- DEC-042 (спека A): «distinct квалификация» для VP stages 2-3 = разные месячные qualification-снапшоты глобального бонуса; учитываются только квалификации, когда достигнутый ранг участника уже VICE_PRESIDENT; ранг навсегда (DEC-020) — «while rank == VP» выполняется всегда после достижения
- T02 предоставляет: константы БС-счёта, метод начисления на БС с созданием кредит-лота и внешним idempotency key, метод выплаты с БС (БС -> company_payouts_paid); T10 не пишет ledger-legs напрямую
- T05 предоставляет rank-событие/hook с payload {member_id, from_rank, to_rank, achieved_at, policy_version_id} (аналог V1 IRankListener)
- Награды при возвратах не отзываются (DEC-027) — reversal-логика T12 обходит source_type='award'
- T10 чисто бэкендовый: админ-UI очереди наград — T13, отображение в Mini App — T14; T10 фиксирует JSON-контракты эндпоинтов
- Расписания/cron в T10 нет; квартальная выплата глобального (T09) и expiry лотов (T02) — вне скоупа
- Форбидден-зоны не затрагиваются: Domain V1, Services/Payment, deploy.yml — только чтение

## T11 — 60%-калибровка выплат (payout pool, DEC-014/029/053)
Зависит от: T06, T07, T09

**Таблицы:**
- v2_pool_calibrations: id, period_id (FK периода T04, месяц), run_version, policy_version_id (T01), base_bv_cents (bigint, BV-оборот периода по снапшотам заказов), pool_rate_bps (default 6000 из PolicyVersion), pool_cap_cents, total_after_caps_cents (числитель: все включённые бонусы после индивидуальных капов + провизорный лидерский), factor_num/factor_den (bigint, точная рациональная дробь; НЕ float), scaled_total_cents, company_retained_cents, status (draft|committed|superseded), created_by, committed_at; UNIQUE(period_id, run_version)
- v2_pool_calibration_items: id, calibration_id (FK), bonus_kind (structure|referral|global|leadership), member_id, source_ref (id строки бонуса из таблиц T06/T07/T08/T09), amount_after_caps_cents, calibrated_cents (floor + largest-remainder), already_posted_cents (для реферальной, уже упавшей на ОС, и структурной H1, уже переведённой НС→ОС 16-го), adjustment_cents (= already_posted − calibrated, может быть 0), ledger_tx_id, state (pending|applied|adjusted|superseded); UNIQUE(calibration_id, bonus_kind, source_ref)
- feature_flags: сид-строка mh_v2_pool (deny-by-default) для админ-эндпоинтов отчёта — существующая таблица, новых колонок нет

**Миграции (по порядку):**
- 2026_07_12_110000_create_v2_pool_calibrations_table.php (слот T11 = 2026_07_12_1100xx, не пересекается с соседями)
- 2026_07_12_110010_create_v2_pool_calibration_items_table.php
- 2026_07_12_110020_seed_v2_pool_feature_flag.php (флаг mh_v2_pool, выключен)

**Backend:**
- ServicesV2/Pool/Contracts/PoolContributorInterface.php — НОВЫЙ контракт, реализуют T06/T07/T08/T09: collectForPeriod(periodId): iterable<PoolContribution{bonus_kind, member_id, source_ref, amount_after_caps_cents, already_posted_cents}> + applyCalibration(items) — каждый бонус-модуль сам постит СВОИ калиброванные суммы идемпотентно (DEC-053: pool → база лидерского → posting)
- ServicesV2/Pool/Contracts/PeriodBvProviderInterface.php + ServicesV2/Pool/PeriodBvProvider.php — база: Σ BV-снапшотов оплаченных заказов (T03) с paid_at в окне месяца (UTC, границы T04), минус возвраты до cutoff
- ServicesV2/Pool/PoolFactor.php — value object: рациональный фактор num/den, применение amount*num/den с floor + детерминированным largest-remainder; инвариант Σcalibrated + company_retained = Σincluded, ни цента не теряется; f<=1 всегда (scale-up невозможен по построению)
- ServicesV2/Pool/PoolCalibrationService.php — оркестратор: собрать вклады у contributors (структурная после капов обеих половин месяца, реферальная, месячное накопление глобального T09, провизорный лидерский от T08 по структурной-после-капов), взять базу BV, посчитать f = min(1, rate_bps*base_bv / 10000*Σ), персистнуть draft → commit; повторный запуск = новая run_version + supersede старой (BR-POOL-002: никогда не перезаписывать)
- ServicesV2/Pool/PoolAdjustmentPoster.php — корректирующие проводки для уже запощенных сумм (реферальная на ОС, структурная H1 после перевода 16-го): дебет счёта участника (ОС/НС из T02) / кредит company_commission_expense, идемпотентный ключ pool:{period}:{run}:{kind}:{source_ref}; при нехватке ОС — clawback_debt по существующему паттерну LedgerService
- ServicesV2/Pool/PoolReportService.php — отчёт по периоду: база BV, по видам бонусов raw/after-caps/calibrated, фактор, удержано компанией, per-member drill-down (API для страницы T13)
- Models/V2/PoolCalibration.php, Models/V2/PoolCalibrationItem.php
- Http/Controllers/V2/PoolAdminController.php — GET /admin/v2/pool/periods (список), GET /admin/v2/pool/periods/{id} (отчёт), GET /admin/v2/pool/periods/{id}/members (постранично), POST /admin/v2/pool/periods/{id}/recalibrate (preview/новая run_version; на CLOSED-периоде — 422, корректировки только через T12)
- Console/V2PoolCalibrateCommand.php — ручной/аварийный запуск calculator:v2:pool-calibrate {period}; регулярный вызов — шаг month-close джобы T04 (контракт: T04 вызывает PoolCalibrationService между стадиями caps и leadership, DAG спеки 06 §7.4)
- Routes/api/v2_pool.php — фичевый роут-файл по образцу feature_flags.php: группа admin (web.admin + calculator.role:owner + feature.flag:mh_v2_pool); + 1 строка require в Routes/api.php
- Providers/CalculatorV2ServiceProvider.php — добавить биндинги Pool-сервисов и команду (файл общий для всех V2-задач)
- Конфиг-ключи в PolicyVersion (T01): payout_pool.enabled (bool), payout_pool.rate_bps (6000), payout_pool.included_bonus_kinds (список; scale-up НЕ конфигурируется — запрещён кодом)

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — правят все V2-задачи (T02–T11); T11 добавляет только биндинги+команду
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require (append-only, конфликт тривиален)
- mh-calc-backend-main/phpunit.xml — если понадобятся env-флаги V2 (стараться обойтись без правок)
- ServicesV2/Pool/Contracts/PoolContributorInterface.php + PoolContribution DTO — владеет T11, но РЕАЛИЗУЮТ T06/T07/T08/T09: контракт должен уехать в merge-train раньше их posting-кода
- Таблицы бонус-строк T06/T07/T09 — T11 читает их id как source_ref и требует колонок amount_after_caps_cents + already_posted-признака (контракт RewardCalculation из спеки 03 §3.3: gross/after_cap/pool_factor/after_pool)

**Риски конфликтов:**
- CalculatorV2ServiceProvider — хотспот всех V2-задач; сериализовать через merge-train, правки только append в отведённом блоке
- Контракт PoolContributorInterface: T06/T07/T08/T09 планируются параллельно и могут не заложить amount_after_caps/already_posted и state PENDING→CALIBRATED→POSTED в своих таблицах — зафиксировать интерфейс на Гейте A до кодинга этих задач
- T08 (лидерский): двусторонняя связка — T11 нуждается в провизорном лидерском для числителя, T08 нуждается в калиброванной структурной для базы (DEC-029); разрешается линейностью (leadership = f × provisional), но порядок вызовов в month-close T04 должен быть зафиксирован: caps(T06) → provisional leadership(T08) → calibrate(T11) → applyCalibration у всех → posting
- T04 (month-close оркестратор): T11 встраивается шагом между caps и posting — риск, что T04 спланирует закрытие месяца без точки расширения; нужен хук/этап payout-pool-reconcile (спека 06 §7.1)
- T07: реферальная постится на ОС сразу — при f<1 месячная калибровка создаёт корректирующие дебеты ОС задним числом; T07 должен хранить помесячную привязку строк и не сопротивляться adjustment-проводкам
- T12 (возвраты): supersede/reversal логика калибровки на закрытых периодах пересекается с корректирующими проводками T12 — договориться, что закрытый период правит только T12-контур, T11 даёт recalibrate только до CLOSED
- Слоты миграций: T11 занимает 2026_07_12_1100xx

**Тест-план:**
- ДЕНЬГИ (обязательные, Unit PoolFactorTest): Σбонусов < 60%·BV → f=1, нулевые adjustment; ровно 60% → f=1; выше → f<1 и Σcalibrated ≤ pool_cap; scale-up невозможен (f никогда >1 даже при Σ≪cap); largest-remainder: Σcalibrated + company_retained = Σincluded с точностью до цента, детерминированность при повторном прогоне; рациональный фактор без float-дрейфа на больших суммах (bigint)
- ДЕНЬГИ (Unit): линейность лидерского — лидерский от калиброванной структурной == f × провизорного лидерского (допуск округления задокументирован и проверен)
- ДЕНЬГИ (Feature PoolCalibrationCloseTest, фикстуры месяца с заказами): структурная H1 уже переведена НС→ОС 16-го → month-close постит корректирующий дебет; реферальная уже на ОС → корректирующий дебет; ОС потрачена → уход в clawback_debt; после всех проводок assertBalanced ledger'а держится; идемпотентность — повторный calibrate того же run = no-op (alreadyPosted); recalibrate → новая run_version, старая superseded реверс-проводками, суммы старой версии не перезаписаны (BR-POOL-002)
- ДЕНЬГИ (Feature): глобальный бонус — месячное накопление входит в числитель, квартальная выплата T09 суммирует уже калиброванные месяцы (не двойная калибровка)
- Инвариант (рандомизированный тест): на случайных наборах бонусов/BV всегда Σвыплат периода ≤ 60%·BV при f<1
- NEGATIVE (права/лимиты): admin-эндпоинты без роли owner → 403; cabinet-токен на admin-роуте → 401/403; флаг mh_v2_pool выключен → 404/403; recalibrate на CLOSED периоде → 422; period_id несуществующий → 404
- Edge: BV периода = 0 при ненулевых бонусах → f=0, все суммы удержаны, отчёт не делит на ноль; период без бонусов → калибровка committed с пустыми items

**Вопросы к Гейту A:**
- Гранулярность калибровки: спека однозначно даёт МЕСЯЦ (payout-pool-reconcile внутри month-close, 06 §7.1/7.4), но структурная — полумесячная с переводом НС→ОС 1/16, т.е. H1 к моменту калибровки уже переведена. Подтвердить: месяц + provisional-перевод H1 с корректировкой на month-close (вариант спеки 06 §7.4)? Альтернатива — держать H1 в НС до закрытия месяца, но это меняет контракт T06 «перевод 1/16».
- Состав числителя: включать ли единоразовые квалификационные награды T10 (БС, до 150k USD) в 60%-пул? DEC-014 говорит «все бонусы», но T11 по роадмапу не зависит от T10, а награда VP разово пробьёт любой месячный пул. Рекомендация: исключить по умолчанию, управлять конфигом payout_pool.included_bonus_kinds.
- Реферальная при f<1: корректирующий дебет ОС может увести участника в минус, если ОС уже потрачена/выведена. Разрешить уход в clawback_debt (существующий механизм ledger) или ограничивать adjustment доступным остатком (недобор — за счёт компании)? Рекомендация: clawback_debt.

**Допущения:**
- «Период» калибровки = календарный месяц (спека 02 §11: слайды 9/14 говорят о месячном 60% глобального оборота; джоба payout-pool-reconcile — зависимость month-close); полумесячные структурные строки обеих половин входят в месячный пул
- Циклическая зависимость лидерский↔пул разрешена в один проход: DEC-029 делает лидерский линейным по фактору, поэтому провизорный лидерский (от структурной после капов) включается в числитель, итог = f × провизорный; итераций не требуется
- База = Σ BV-снапшотов оплаченных заказов месяца (контракт T03 через T06/T07), возвраты до cutoff уменьшают базу; детальная логика возвратов — T12
- Ставка 60% хранится как rate_bps=6000 в PolicyVersion (T01) и применяется по версии, действующей на дату начала периода; scale-down-only захардкожен (не конфиг), как решил владелец по DEC-014
- Posting калиброванных сумм остаётся за бонус-модулями (T06–T09) через applyCalibration — T11 владеет только фактором, items и корректирующими проводками для уже запощенного; это соответствует DEC-053 raw→caps→pool→leadership base→posting
- CalculatorV2ServiceProvider и таблица периодов T04 существуют к моменту кодинга T11 (T11 в волне после T06/T07/T09); если V2-провайдера ещё нет — T11 создаёт его по рекомендации preflight
- Админ-UI отчёта 60%-пула — скоуп T13 (явно в его фокусе); T11 отдаёт только API, поэтому frontend-массивы пусты
- Все суммы integer USD-центы, фактор — рациональное число num/den в bigint; float не используется нигде в money-path
- Ручная выплата (payout-контур) не меняется: калибровка влияет на начисления на счета ОС/НС, а не на механику вывода

## T12 — Возвраты и сторно (reversal-движок V2: PV-лоты, все бонусы, корректировки закрытых периодов)
Зависит от: T06, T07, T08, T09, T10

**Таблицы:**
- v2_order_returns: id, order_id FK->orders, member_id, kind(full|partial), status(draft|reversing|reversed|needs_manual|failed), reason text, returned_bv_cents int, returned_pv decimal(12,2), policy_version_id, created_by_admin_id, idempotency_key unique, timestamps
- v2_order_return_lines: id, return_id FK, order_item_id FK->order_items, qty int, returned_pv decimal, returned_bv_cents int (иммутабельный снапшот из OrderItem по DEC-003, не из текущего каталога)
- v2_reversal_actions: id, return_id FK, action_type(pv_lot_reversal|match_compensation|bonus_reversal|clawback|tier_basis_adjust|qualification_note|period_correction_proposed), bonus_type nullable(structural|referral|leadership|global), target_type/target_id (лот/матч/bonus-line), amount_cents signed int, amount_pv decimal, snapshot_json (original rate/tier/rank/scale-factor), ledger_tx_id uuid nullable, status(pending|posted|skipped), idempotency_key unique — журнал шагов = reversal chain и explainability, ledger_entries НЕ альтерим
- v2_period_corrections: id, period_id FK->v2_periods(T04), return_id FK nullable, member_id, bonus_type, amount_cents signed, status(proposed|approved|posted|rejected), reason, snapshot_json, approved_by_admin_id, approved_at, ledger_tx_id, idempotency_key unique — очередь корректирующих проводок закрытых периодов (DEC-027)

**Миграции (по порядку):**
- 2026_07_12_120000_create_v2_order_returns_table.php
- 2026_07_12_120010_create_v2_order_return_lines_table.php
- 2026_07_12_120020_create_v2_reversal_actions_table.php
- 2026_07_12_120030_create_v2_period_corrections_table.php
- 2026_07_12_120040_seed_feature_flag_mh_v2_refunds.php (deny-by-default, по образцу C3)
- УСЛОВНО 2026_07_12_120050_add_reversal_of_lot_id_to_v2_pv_lots.php — ТОЛЬКО если T03 не заложил reversal-link в лоты (DEC-018); согласовать на Gate A, чтобы не было двойной миграции

**Backend:**
- Modules/Calculator/ModelsV2/OrderReturn.php, OrderReturnLine.php, ReversalAction.php, PeriodCorrection.php — Eloquent-модели
- Modules/Calculator/ServicesV2/Refunds/RefundService.php — оркестратор: валидация (заказ paid, qty<=ordered, не задвоен), создание return+lines в DB::transaction под advisory-lock ACTIVATION_LOCK_KEY (0x12916001, тот же что recompute — исключает гонку с пересчётом), прогон плана, перевод Order в STATUS_REFUNDED; идемпотентность по idempotency_key
- Modules/Calculator/ServicesV2/Refunds/ReversalPlanner.php — детерминированный план: находит PV-лоты заказа (репозиторий T03, provenance source_order_item_id), затронутые матчи, bonus-lines T06-T09 по source-атрибуции, делит эффекты на open-period (прямой reversal) vs closed-period (корректировка-предложение); НИКОГДА не считает по текущим tier/rank — только original snapshots (CAL-REV-001)
- Modules/Calculator/ServicesV2/Refunds/PvLotReversalService.php — сторно несматченных лотов (отрицательный лот с reversal-link), компенсационные записи по уже сматченным лотам (без возврата лота в очередь FIFO); интерфейс к лотам/матчам T03
- Modules/Calculator/ServicesV2/Refunds/BonusReversalService.php — точные inverse-проводки на счета ОС/НС через posting-API T02: referral (немедленно, по original rate+tier снапшоту), structural/leadership/global (по posted-атрибуции bonus-lines, уже включающей капы и 60%-scale); при нехватке ОС — clawback-долг (паттерн V1 ACC_CLAWBACK_DEBT на V2-субсчёте); все группы через post()/assertBalanced()/alreadyPosted()
- Modules/Calculator/ServicesV2/Refunds/PeriodCorrectionService.php — создание proposed-корректировок по закрытым периодам, approve/reject (owner), post (отдельная идемпотентная проводка); закрытый период НЕ переоткрывается, исходные run/строки не редактируются
- Modules/Calculator/ServicesV2/Refunds/RequalificationService.php — пере-оценка через снапшот: tier basis PV минус returned_pv (тир НЕ понижается, DEC-010; влияет только на будущие апгрейды), негативные volume-facts для будущих квалификаций; ранг НЕ отзывается (DEC-020), награды T10 НЕ сторнируются — фиксируется qualification_note в v2_reversal_actions
- Modules/Calculator/Http/Controllers/V2/RefundAdminController.php — create/show/list returns, list/approve/post corrections
- Modules/Calculator/Routes/api/v2_refunds.php — admin-группа (web.admin + calculator.role:owner) под middleware feature.flag:mh_v2_refunds; + ОДНА строка require в Routes/api.php
- Регистрация DI в CalculatorV2ServiceProvider (создаётся T01; T12 добавляет свой блок биндингов)
- Правка Modules/Calculator/Services/OrderService.php::setStatus — при включённом mh_v2_refunds запретить прямой перевод paid-заказа в refunded мимо RefundService (guard, ~5 строк)
- Tests/Unit/V2/Refunds/* и Tests/Feature/V2/RefundAdminTest.php

**Фронт: админка:**
- mh-calc-frontend-main/src/views/admin/web/refunds/refunds-v2.nav.js — своя секция + одна строка в blockCSections в src/views/admin/web/nav/registry.js (маркер-паттерн)
- mh-calc-frontend-main/src/views/admin/web/refunds/RefundsV2View.js — список возвратов, форма создания возврата по заказу (full/partial), детализация reversal chain, очередь корректировок закрытых периодов с approve/post
- mh-calc-frontend-main/src/views/admin/api.js — append-only функции listReturnsV2/createReturnV2/getReturnV2/listPeriodCorrectionsV2/approveCorrectionV2/postCorrectionV2

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require '/api/v2_refunds.php'
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php / CalculatorV2ServiceProvider.php — блок DI-регистраций T12 (горячий файл всего блока)
- mh-calc-backend-main/Modules/Calculator/Services/OrderService.php — guard в setStatus (правят также T03 снапшоты)
- mh-calc-frontend-main/src/views/admin/web/nav/registry.js — одна строка в blockCSections (правит и T13)
- mh-calc-frontend-main/src/views/admin/api.js — append-only (правит и T13)
- mh-calc-backend-main/phpunit.xml — только если понадобится env-флаг для тестов (стараться обойтись без)

**Риски конфликтов:**
- ГЛАВНЫЙ межзадачный контракт: T06/T07/T08/T09 обязаны сохранять в bonus-lines source-атрибуцию (source_order_id / lot_ids / downline bonus_line_ids) и ФИНАЛЬНЫЕ posted-центы после капов и 60%-scale + ledger_tx_id + period_id. Без этого T12 не может строить exact-inverse. Зафиксировать формат на Gate A, иначе T12 будет альтерить чужие таблицы
- T03: reversal-link и provenance у PV-лотов (DEC-018) — если T03 не заложит reversal_of_lot_id и статус consumed-by-match, T12 добавляет колонку миграцией к чужой таблице (условная миграция 120050)
- T04: владение примитивом 'корректирующая проводка закрытого периода' — если T04 сам заведёт corrections-таблицу, T12 переиспользует её вместо v2_period_corrections (убрать дубль на Gate A)
- T11 (НЕ в depends_on, но связан): 60%-scale вшит в posted-суммы — T12 сторнирует posted (после scale), а отчёт 60%-пула T11 должен учитывать корректировки периода; согласовать, чтобы отчёт T11 включал v2_period_corrections/reversal-проводки
- T13: одна строка в nav/registry.js и append в admin/api.js — merge-train, конфликт тривиален
- T15: parity-прогон V1 vs V2 должен знать, что reversal-проводки существуют только в V2 (V1 возврат = чисто статус заказа) — иначе ложные расхождения
- CalculatorV2ServiceProvider — общий хотспот всех задач V2; регистрироваться блочными маркерами
- Timestamp-слот миграций 2026_07_12_1200xx закреплён за T12 — соседям не занимать

**Тест-план:**
- ДЕНЬГИ (обязательные): reversal referral-бонуса по original rate/tier снапшоту, даже если тир получателя с тех пор изменился — суммы строго равны исходной проводке с обратным знаком; повторный вызов с тем же idempotency_key = no-op (alreadyPosted)
- ДЕНЬГИ: частичный возврат — пропорция строго по снапшоту return_lines; сумма reversal по всем строкам == атрибуции исходной проводки без дрейфа центов (rounding-инвариант)
- ДЕНЬГИ: сторно structural после закрытия half-month через corrections — сумма = posted-атрибуция (включая cap и 60%-scale), каскад leadership от тех же строк; assertBalanced на каждой tx-группе
- ДЕНЬГИ: нехватка ОС у получателя — уход в clawback-долг, ledger сбалансирован, wallet-кэш консистентен
- PV-лоты: возврат несматченного лота — отрицательный лот с reversal-link, free-остатки веток корректны; возврат уже сматченного PV — компенсационная запись матча, лот НЕ возвращается в начало FIFO-очереди
- Инварианты 'ранг навсегда': возврат qualifying-заказа — статус НЕ понижен, награда T10 НЕ сторнирована, тир НЕ понижен, но tier_basis_pv уменьшен (будущий апгрейд отодвинут); qualification_note записан
- Закрытый период: прямых проводок НЕТ, создаётся proposed-корректировка; posted только после approve; исходные run/строки не изменены
- NEGATIVE права/лимиты: не-owner не может создать возврат/approve корректировку (403); флаг mh_v2_refunds OFF — роуты недоступны; возврат qty>ordered — 422; возврат неоплаченного заказа — 422; повторный полный возврат — 422; approve уже posted корректировки — 409/422; setStatus('refunded') напрямую при включённом флаге — отказ
- Конкурентность: возврат сериализуется с recompute под ACTIVATION_LOCK_KEY (feature-тест на advisory-lock)
- Feature-тест end-to-end: заказ paid → бонусы T06/T07 начислены → полный возврат в открытом периоде → все балансы ОС/НС и лоты вернулись к состоянию до бонусов (кроме нерушимых ранга/награды/тира)

**Вопросы к Гейту A:**
- Частичные возвраты в первой версии: поддерживать line-level partial (qty по позициям) или ограничиться только полным возвратом заказа? DEC-012 говорит, что кейс редкий и ручной — full-only заметно дешевле; спека требует partial. Рекомендация: заложить схему под partial (return_lines), в UI первой версии дать только full
- Утверждение корректировок закрытых периодов: достаточно ли одной owner-роли (админ один), или нужен four-eyes из спеки §7 (создал один — утвердил другой)? Рекомендация: одна owner-роль + обязательный reason + audit_log
- Глобальный бонус: возврат, уменьшающий пул уже закрытого МЕСЯЦА до квартальной выплаты — пересчитывать доли всех участников месяца (корректировка каждому) или корректировать только сторону компании, а после квартальной выплаты — всегда ручное решение? Рекомендация: до выплаты — уменьшить снапшот пула одной корректировкой, доли не пересчитывать; после выплаты — только ручные proposed-корректировки
- Подтвердить: возврат денег покупателю (USDT) полностью ВНЕ системы (админ платит руками), система только фиксирует факт и сторнирует внутренние начисления — покупателю на ОС ничего не зачисляется?

**Допущения:**
- Конвенция блока: неймспейсы Modules/Calculator/ServicesV2 и ModelsV2, префикс таблиц v2_, CalculatorV2ServiceProvider создан T01 — T12 только добавляет свой блок
- Bonus-постинги T06-T09 несут source-атрибуцию и финальные posted-центы (контракт Gate A); reversal строится ТОЛЬКО из этих снапшотов, deterministic replay периода НЕ реализуем — выбрана компенсационная политика (допустимо по DEC-027/012: возвраты редкие и ручные)
- ledger_entries (V1-таблица) НЕ альтерим: reversal chain хранится в v2_reversal_actions (ledger_tx_id обеих сторон); механизм post()/assertBalanced()/alreadyPosted() LedgerService переиспользуется как есть
- T02 предоставляет posting-API субсчетов ОС/НС/БС и clawback-путь; reversal идёт на тот же субсчёт, куда шло исходное начисление (referral/leadership/global → ОС, structural до перевода → НС)
- T04 предоставляет Period(open/closed) с period_id; если T04 введёт свой corrections-примитив — v2_period_corrections заменяется на него
- Пока флаг mh_v2_refunds OFF, поведение прода не меняется: возврат = смена статуса заказа как сейчас (V1 без финансового сторно)
- TON Pay/Services/Payment не трогаем — возврат средств покупателю вне системы; frontend miniapp не трогаем (история счетов покажет reversal-проводки через T14)
- Timestamp-слот миграций T12: 2026_07_12_1200xx

## T13 — Админка V2: редактор PolicyVersion, периоды, счета ОС/НС/БС с лотами, отчёт 60%-пула, очередь наград
Зависит от: T01 (PolicyVersionService + PolicyConfigValidator + таблица v2_policy_versions: version, status DRAFT/APPROVED/ACTIVE/RETIRED, valid_from/valid_to, config JSON в USD-центах, checksum, created_by/approved_by; интервалы ACTIVE не пересекаются), T02 (таблица кредит-лотов v2_wallet_lots: member_id, account OS/NS/BS, amount_cents, remaining_cents, earned_at, expires_at, status, source_type/source_id + проекция балансов субсчетов; read-only доступ для админ-страниц), T04 (таблица v2_calc_periods: kind HALF_MONTH/MONTH/QUARTER, start_at/end_at, status OPEN/CLOSED, closed_at, policy_version_id + таблица runs/снапшотов закрытия; read-only доступ), T10 — НЕ DAG-зависимость: контракт таблицы v2_rank_reward_entitlements и RewardPayoutHandlerInterface для действия mark-paid (до T10 — пустая очередь, действие 409), T11 — НЕ DAG-зависимость: контракт таблицы v2_period_calibrations для отчёта 60%-пула (до T11 — пустой отчёт 200)

**Таблицы:**
- СОБСТВЕННЫХ ТАБЛИЦ НЕТ — T13 только читает/оркеструет чужие + пишет в существующий admin_audit_log (actor_member_id, action, entity_type, entity_id, before/after JSON) действиями policy.v2.create|update|validate|activate, reward.v2.mark_paid
- feature_flags (существующая) — сид-строка mh_v2_admin, enabled=false, deny-by-default
- Консумируемые (контракты, владельцы — T01/T02/T04/T10/T11): v2_policy_versions, v2_wallet_lots (+проекция балансов OS/NS/BS), v2_calc_periods (+runs/snapshots), v2_rank_reward_entitlements (member_id, rank_code, amount_cents, status pending/paid/held, awarded_at, paid_at, idempotency_key, ledger_tx_id), v2_period_calibrations (period_id, base_bv_cents, raw_total_cents, final_total_cents, ratio, scale_factor, breakdown_by_bonus JSON, status)

**Миграции (по порядку):**
- 2026_07_12_130000_seed_feature_flag_mh_v2_admin.php — insert в feature_flags: key=mh_v2_admin, enabled=false, description (слот timestamp T13: 2026_07_12_1300xx, не пересекается с другими задачами блока)

**Backend:**
- mh-calc-backend-main/Modules/Calculator/Routes/api/v2_admin.php — НОВЫЙ роут-файл фичи: группа admin (web.admin + feature.flag:mh_v2_admin); GET policy-versions, GET policy-versions/{id} (owner,finance); POST policy-versions, PUT policy-versions/{id} (только DRAFT), POST policy-versions/{id}/validate, POST policy-versions/{id}/activate (owner); GET periods?kind&status, GET periods/{id} (owner,finance,support); GET members/{id}/accounts, GET members/{id}/lots?account&status (owner,finance); GET periods/{id}/calibration (owner,finance); GET rewards?status (owner,finance), POST rewards/{id}/mark-paid (owner)
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require __DIR__.'/api/v2_admin.php' в конец блока require (строки ~257-263)
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2Admin/PolicyVersionAdminController.php — CRUD черновиков, validate (прокидывает ошибки PolicyConfigValidator из T01), activate; правка ACTIVE/RETIRED → 409 (новая версия вместо правки, по спеке 05 §4.5); каждый мутирующий вызов пишет audit before→after
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2Admin/PeriodAdminController.php — read-only список/деталь периода (runs, снапшот закрытия, policy_version)
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2Admin/AccountAdminController.php — read-only счета партнёра OS/NS/BS (integer-центы) + лоты, сортировка earliest-expiry-first, маркировка истёкших/сгоревших
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2Admin/CalibrationReportAdminController.php — read-only отчёт 60%-пула по периоду; при отсутствии данных T11 — 200 с пустым состоянием
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2Admin/RewardQueueAdminController.php — список entitlements; mark-paid с idempotency-key, делегирование в RewardPayoutHandlerInterface (T10), 409 если хендлер не забинден; audit recordStrict (fail-closed, по образцу PII-reveal в AuditLogService)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Admin/PolicyAdminService.php — обёртка над PolicyVersionService (T01) + AuditLogService: diff before/after, запрет overlap ACTIVE-интервалов через валидатор T01
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Admin/AccountsReadService.php — чтение балансов/лотов T02 + агрегаты (доступно/истекает за 30 дней/сгорело)
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Admin/PeriodsReadService.php — чтение v2_calc_periods + runs/снапшоты T04
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Admin/RewardQueueAdminService.php + ServicesV2/Contracts/RewardPayoutHandlerInterface.php — интерфейс, реализацию биндит T10; T13 владеет только контрактом
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — регистрация 4 админ-сервисов в маркер-блоке '>>> T13 admin' (файл создаёт T01; T13 добавляет только свой блок)
- mh-calc-backend-main/Modules/Calculator/Tests/Feature/V2AdminPolicyTest.php, V2AdminAccountsTest.php, V2AdminPeriodsTest.php, V2AdminRewardQueueTest.php, V2AdminCalibrationReportTest.php

**Фронт: админка:**
- mh-calc-frontend-main/src/views/admin/web/v2/MarketingV2.js — shell-экран секции с antd Tabs: Политика / Периоды / Счета / 60%-пул / Награды (одна секция меню вместо пяти — меньше строк в registry)
- mh-calc-frontend-main/src/views/admin/web/v2/PolicyVersions.js — список версий со статусами; редактор конфига DRAFT (raw JSON + серверная валидация с рендером ошибок по путям), кнопки Validate/Activate (activate — с confirm и valid_from), read-only просмотр ACTIVE/RETIRED, суммы в USD из центов через существующий format.js
- mh-calc-frontend-main/src/views/admin/web/v2/PeriodsV2.js — таблица периодов (kind/границы/статус/policy_version), деталь: runs и снапшот закрытия; полностью read-only
- mh-calc-frontend-main/src/views/admin/web/v2/MemberAccountsV2.js — поиск партнёра → карточка ОС/НС/БС + таблица лотов (остаток, earned_at, expires_at, источник), бейджи «истекает ≤30 дней»/«сгорел»
- mh-calc-frontend-main/src/views/admin/web/v2/PoolReport.js — по периоду: BV-база, raw/после капов/финал, ratio к 60%, scale_factor, разбивка по видам бонусов; пустое состояние до T11
- mh-calc-frontend-main/src/views/admin/web/v2/RewardsQueue.js — очередь entitlements (партнёр, ранг, сумма, статус), owner-only действие mark-paid с confirm; 409 «движок не подключён» до T10
- mh-calc-frontend-main/src/views/admin/web/v2/apiV2.js — фичелокальный API-клиент: import { req } from '@/views/admin/webApi' + локальный mutate-хелпер (mutate в webApi.js не экспортирован) — webApi.js НЕ трогаем
- mh-calc-frontend-main/src/views/admin/web/nav/mh_v2.nav.js — { key:'mh-v2', label:'План V2', roles:['owner','finance'], flag:'mh_v2_admin', render: () => <MarketingV2/> }
- mh-calc-frontend-main/src/views/admin/web/nav/registry.js — одна строка mhV2Nav внутри маркеров '>>> Block C sections' (фильтрация по флагу уже реализована в visibleBlockCSections)
- mh-calc-frontend-main/src/locales/ru/* и src/locales/en/* — ключи переводов секции mhV2.* (en-каталог на фронте существует)

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — +1 строка require (правят T02, T03, T05-T14)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — общий V2-провайдер (создаёт T01, правят T02/T04/T06-T13) — только маркер-блок T13
- mh-calc-frontend-main/src/views/admin/web/nav/registry.js — +1 строка в blockCSections (в блоке правит только T13, низкий риск)
- mh-calc-frontend-main/src/locales/ru|en — общие JSON переводов (возможное пересечение с T14, если i18n-файлы общие для admin и miniapp)
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — общий каталог, слот T13: 2026_07_12_1300xx
- НЕ трогаем: WebAdminShell.js, webApi.js, CalculatorServiceProvider.php, phpunit.xml, RouteServiceProvider.php

**Риски конфликтов:**
- CalculatorV2ServiceProvider — самый горячий файл блока: T13 добавляет 4-5 биндингов; без маркер-блоков конфликт с T02/T04/T06-T12 почти гарантирован — сериализовать через merge-train, T13 мерджить ПОСЛЕ T01/T02/T04
- Дрейф контрактов таблиц T10 (v2_rank_reward_entitlements) и T11 (v2_period_calibrations): они НЕ в depends_on T13 и могут кодиться параллельно/позже — имена таблиц и колонок зафиксировать на Гейте A, иначе страницы очереди наград и 60%-отчёта придётся переписывать
- RewardPayoutHandlerInterface: контракт владеет T13, реализацию биндит T10 — если T10 самостоятельно заведёт свой admin-endpoint mark-paid, получится дубль денежного действия; разграничить на Гейте A (рекомендация: endpoint у T13, вся денежная логика у T10)
- Routes/api.php — однострочный require-конфликт со всеми задачами блока (тривиально разрешимый, но каждая пара веток его словит)
- PolicyVersions-редактор пересекается по смыслу со старым экраном MarketingPlan.js/PlanSettings (V1 plan-settings): не трогать V1-экран до cutover (T15), новая секция живёт параллельно под флагом
- Слоты timestamp миграций: коллизия 2026_07_12_* между задачами — T13 резервирует 1300xx
- src/locales общие JSON — append-only ключи, но параллельная правка с T14 даст конфликт merge в тех же файлах

**Тест-план:**
- RBAC negative (обязательно, деньги/конфиг): без токена → 401; support → 403 на PUT policy-versions и mark-paid; finance → 403 на activate; owner → 200. Отдельно: flag mh_v2_admin OFF → все /admin/v2/* недоступны (поведение EnsureFeatureFlag), ON → доступны
- PolicyVersion (деньги-критично, конфиг движка): невалидный конфиг → 422 с путями ошибок и БЕЗ сохранения; правка версии в статусе ACTIVE/RETIRED → 409; activate с пересечением интервалов ACTIVE → 422 (через валидатор T01); каждый create/update/activate создаёт запись admin_audit_log с корректными before/after (суммы integer-центы)
- Счета и лоты (деньги, read): фикстуры лотов ОС/НС/БС → балансы в integer-центах сходятся с суммой remaining_cents лотов; сортировка earliest-expiry-first; истёкший лот не входит в available; сумма 'истекает ≤30 дней' корректна
- Периоды: список фильтруется по kind/status; деталь CLOSED-периода отдаёт снапшот; мутирующих роутов периодов НЕТ (тест на 405/404 для POST)
- Отчёт 60%-пула: с фикстурой калибровки → base_bv/raw/final/scale_factor корректны и raw*scale=final в центах (largest-remainder не наш, но сверка сумм обязательна); без данных → 200 пустое состояние, не 500
- Очередь наград (деньги, mutation — максимум внимания): mark-paid без забинденного RewardPayoutHandlerInterface → 409 и НОЛЬ изменений; с fake-хендлером: повторный POST с тем же idempotency-key → одна транзакция статуса; mark-paid уже paid → 409; audit recordStrict — при падении записи аудита действие откатывается (fail-closed)
- Фронт (лёгкий, пропорционально риску): unit-тест registry — секция mh-v2 скрыта при flags={} и видна при {mh_v2_admin:true}; smoke-рендер PolicyVersions с ошибками валидации

**Вопросы к Гейту A:**
- Периоды из админки: строго read-only (закрытие — только джобы T04, рекомендация) или нужна кнопка ручного запуска закрытия/REOPENED из спеки 05 §4.8? Роадмап говорит «запрет изменения закрытых периодов» — предлагаю read-only в T13.
- Активация PolicyVersion: спека требует four-eyes approve (draft→validate→approve→activate), но в IziGo фактически один owner. Упрощаем до owner-activate с confirm и полным аудитом (рекомендация) или делаем двухшаговый approve+activate «на вырост»?
- Очередь наград — граница T13/T10: T13 делает UI+endpoint mark-paid, делегирующий в интерфейс, реализацию биндит T10 (рекомендация). Подтвердить, что T10 не заводит собственный admin-endpoint, и нужен ли админу дополнительно hold/forfeit в первой версии.
- Контракты таблиц не-зависимостей: подтвердить на Гейте A имена/форму v2_period_calibrations (T11) и v2_rank_reward_entitlements (T10), т.к. T13 строит против них read-страницы до их реализации.
- UX редактора конфига: первая версия — raw JSON с серверной валидацией и diff в аудите (рекомендация, быстро и безопасно) или структурированная форма по разделам (статусы/ставки/капы/награды — заметно дороже)?

**Допущения:**
- T01 отдаёт в ServicesV2 PolicyVersionService и PolicyConfigValidator (schema+semantic, ошибки с путями) и создаёт CalculatorV2ServiceProvider — T13 валидацию НЕ реализует, только вызывает и рендерит ошибки
- Статусная модель версии — DRAFT/APPROVED/ACTIVE/RETIRED по спеке 05 §4.5; редактируется только DRAFT; активная версия неизменяема (новая версия вместо правки)
- T02 хранит лоты в отдельной таблице (не расширение member_wallets) с amount/remaining в integer-центах и expires_at на лот; балансы OS/NS/BS доступны как проекция — T13 читает без собственных денежных вычислений
- T04 даёт периоды HALF_MONTH/MONTH/QUARTER со статусами и снапшотами закрытия; run-структура читается как есть
- Флаг mh_v2_admin — отдельный UI-флаг, независимый от cutover-флага расчёта (mh_plan_v2, T15): админку V2 можно включить до переключения движка для подготовки конфига
- Роли: чтение — owner+finance (периоды также support, по прецеденту plan-settings GET), все мутации — owner-only; RBAC-гейт на бэке, фронт только скрывает
- Страницы 60%-пула и очереди наград деградируют в пустое состояние до мерджа T11/T10 — T13 не блокируется их отсутствием
- Все суммы в API — integer USD-центы, конверсия в доллары только на рендере (существующий format.js); KZT в UI не показываем (курс 468 зафиксирован на уровне конфига)
- Скидка MH и подписка не реализуются (решения владельца) — в редакторе конфига referral_stop_at_elite отображается как обычный флаг с дефолтом false, отдельного UI под скидку/подписку нет
- Admin UI — antd + react-i18next по образцу FeatureFlags.js; en-каталог локалей на фронте существует

## T14 — Mini App V2: прогресс 12 статусов, счета ОС/НС/БС, тир, награды (RU/EN)
Зависит от: T02 — счета OS/NS/BS: сервис балансов/лотов/истории поверх ledger (потребляем read-контракт), T05 — лестница статусов/тиры: текущий ранг, снапшот квалификации (variant, qualifier_partner_id, root_branch_id), tier по накопленному personal PV, evaluator с breakdown-режимом (потребляем)

**Таблицы:**
- НОВЫХ доменных таблиц НЕТ — T14 чисто read-слой (проекции) поверх таблиц T02/T05
- Потребляем (контракт T02): таблица кредит-лотов субсчетов (member_id, account os|ns|bs, amount_cents int, remaining_cents int, credited_at, expires_at, source/provenance, status) + ledger_entries по новым константам счетов + денормализованные балансы субсчетов
- Потребляем (контракт T05): состояние ранга участника (current_rank_key, achieved_at, 'ранг навсегда') + снапшот квалификации (variant 1|2|3, qualifier_partner_id[], root_branch_id[], evaluated_at) + tier state (tier_key, basis_pv накопленный personal PV)
- Потребляем (контракт T01 через T05): PolicyVersion-конфиг — каталог 12 статусов с порогами малой ветки, пороги тиров 100-199/200-599/600+, суммы наград в USD-центах
- feature_flags — существующая таблица C3: добавляем сид-запись mh_plan_v2_miniapp (disabled)

**Миграции (по порядку):**
- 2026_07_12_140001_seed_feature_flag_mh_plan_v2_miniapp.php — единственная миграция T14: сид флага mh_plan_v2_miniapp (enabled=false, описание) в feature_flags; слот таймстампов T14 = 2026_07_12_1400xx, не пересекается с V1 и другими задачами блока

**Backend:**
- Routes/api/miniapp_v2.php — НОВЫЙ фича-роут-файл (образец Routes/api/feature_flags.php): группа prefix=cabinet, middleware ['telegram.auth','feature.flag:mh_plan_v2_miniapp']; эндпоинты: GET /cabinet/v2/plan/overview (ранг+тир+балансы трёх счетов одним вызовом для шапки таба); GET /cabinet/v2/plan/rank-progress (лестница 12 статусов, текущий/достигнутые со снапшотом, для следующего статуса: прогресс малой ветки PV + разбор 3 вариантов requirements-vs-actuals с учётом разных корневых ветвей); GET /cabinet/v2/plan/accounts (балансы os/ns/bs в центах, ближайшие сгорания лотов, next_transfer_at НС→ОС 1/16, order_pay_limit_pct=70 для ОС); GET /cabinet/v2/plan/accounts/{account}/lots (активные лоты, сортировка earliest-expiry-first, account in os|bs|ns → 422 на прочее); GET /cabinet/v2/plan/accounts/{account}/history?cursor= (лента ledger-движений субсчёта, cursor-пагинация как wallet/transactions); GET /cabinet/v2/plan/awards (каталог наград по рангам из PolicyVersion + state locked|earned|credited по истории рангов и БС-зачислениям, DEC-040: при скачке видны все пройденные)
- Routes/api.php — одна строка require __DIR__.'/api/miniapp_v2.php' в хвосте (строки 257-263, append-only)
- Http/Controllers/CabinetV2Controller.php — НОВЫЙ тонкий контроллер по образцу CabinetController: member-only (лид → доменная ошибка → 404), guarded()-обёртка {status,data}; вся логика в read-сервисах
- ServicesV2/Read/CabinetPlanReadService.php — агрегатор overview (ранг/тир/балансы одним DTO)
- ServicesV2/Read/RankProgressReadService.php — маппинг breakdown'а evaluator'а T05 в payload прогресса; НЕ реализует квалификационную логику заново, только проекция requirements/actuals/satisfied/missing по 3 вариантам + прогресс малой ветки; ранги/тиры/варианты — машинные коды (GOLD_MANAGER, elite, variant:2), все названия локализует фронт
- ServicesV2/Read/AccountsReadService.php — обёртка над сервисом счетов T02: балансы (int-центы), лоты с expires_at, история по субсчёту; ничего не пишет в ledger
- ServicesV2/Read/AwardsReadService.php — каталог наград из PolicyVersion-конфига (центы) + статус по истории достигнутых рангов и зачислениям БС; интерфейсная точка под T10 (когда очередь entitlement'ов T10 вмерджится — переключить источник, см. риски)
- Регистрация read-сервисов — append-блок с маркерами '>>> T14 miniapp v2 read' в CalculatorV2ServiceProvider (создаётся T01/T02; T14 добавляет только свои строки)
- Контракты: все деньги integer USD-центы, PV — decimal-строка; API не отдаёт человекочитаемых текстов (кроме дат ISO) — RU/EN целиком на фронте, en-каталог бэкенд-локалей не требуется

**Фронт: Mini App:**
- src/views/miniapp/tabs/planv2.tab.js — НОВЫЙ таб-объект: key='planv2', label='planv2.title' (i18n-ключ), icon, flag='mh_plan_v2_miniapp'; render=(ctx)=><PlanV2Tab initData pal isDark me/>; регистрация одной строкой в blockCTabs внутри маркеров registry.js
- src/views/miniapp/tabs/registry.js — 1 строка import + 1 строка в массиве blockCTabs (маркер-блок '>>> Block C tabs')
- src/views/miniapp/planv2/PlanV2Tab.js — НОВЫЙ контейнер одного таба с Segmented-переключателем секций: Статус / Счета / Награды (один таб вместо трёх — не раздуваем таб-бар)
- src/views/miniapp/planv2/StatusProgress.js — визуальная лестница 12 статусов (текущий подсвечен, 'ранг навсегда' — без регресса), прогресс-бар малой ветки PV к следующему статусу, карточки 3 вариантов квалификации с чек-листом requirements/actuals (включая пометку 'из разных ветвей'), бейдж тира START/BUSINESS/ELITE с накопленным personal PV и порогами
- src/views/miniapp/planv2/AccountsPanel.js — три карточки ОС/НС/БС: баланс (format.js usd()), свойства счёта (ОС: вывод + оплата ≤70%; НС: дата ближайшего перевода 1/16; БС: только покупки), список активных лотов со сроками сгорания (earliest-expiry first, предупреждение <30 дней), история движений с cursor-подгрузкой
- src/views/miniapp/planv2/AwardsPanel.js — награды по рангам: сумма USD, статус locked/earned/credited; earned при скачке показывает все пройденные ступени
- src/views/miniapp/api.js — append-блок с маркерами '>>> MH Plan V2': mmPlanOverview, mmPlanRankProgress, mmPlanAccounts, mmPlanAccountLots(account), mmPlanAccountHistory(account,cursor), mmPlanAwards — все через существующий req()
- src/locales/ru/translation.json и src/locales/en/translation.json — namespace planv2.* : названия 12 статусов RU/EN, тиров, счетов (Основной/Накопительный/Бонусный · Main/Accumulation/Bonus), текстов вариантов квалификации, наград, сроков сгорания; ОБА языка обязательны (фокус T14)

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна require-строка (конфликт с T02,T03,T05-T13; append-only хвост)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorV2ServiceProvider.php — общий V2-провайдер (создаёт T01/T02, дописывают T03-T14); T14 — только маркер-блок регистраций
- mh-calc-frontend-main/src/views/miniapp/tabs/registry.js — маркер-блок blockCTabs (T14 единственный из блока, но файл общий с будущими фичами)
- mh-calc-frontend-main/src/views/miniapp/api.js — append-блок функций
- mh-calc-frontend-main/src/locales/ru/translation.json и src/locales/en/translation.json — общие с T13 (админка) словари; ключи в своём namespace planv2.*, но git-конфликты на файле вероятны
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — общий каталог, слот 2026_07_12_1400xx закреплён за T14

**Риски конфликтов:**
- T05-контракт (главный): нужен evaluator в 'explain/progress'-режиме (requirements vs actuals по каждому из 3 вариантов + текущее PV малой ветки), а не только boolean-квалификация. Если T05 отдаёт только результат — T14 придётся дублировать квалификационную логику в read-слое (недопустимо, деньги/статусы разъедутся). Зафиксировать метод breakdown в плане T05 до старта кодинга T14
- T02-контракт: имена сервиса/таблиц лотов и коды субсчетов (os|ns|bs) берём из плана T02; T14 не должен читать ledger_entries напрямую мимо сервиса T02, иначе после доработок T02 история разъедется с балансами
- T10 (не зависимость): очередь квалификационных наград появится позже. AwardsReadService в T14 деривирует статус из истории рангов + БС-зачислений; после мерджа T10 источником должна стать его entitlement-таблица — заложить это как интерфейс (swap-точка), согласовать с планом T10, иначе двойная правда о статусе награды
- Имя фича-флага: mh_plan_v2_miniapp (UI-гейт) НЕ равен движковому флагу cutover T15 (mh_plan_v2) — не переиспользовать один флаг, иначе включение UI досрочно включит расчёт V2 (или наоборот)
- CalculatorV2ServiceProvider — горячий файл всех V2-задач; писать только внутри собственного маркер-блока, merge-train блока сериализует
- locales translation.json — общий файл с T13: конфликты git при параллельной работе; разруливается разными namespace и порядком мерджа
- Порядок мерджа: T14 кодить можно параллельно, но Feature-тесты зелёные только после T02+T05 в release/mh-full-plan — ставить T14 в волну после них
- MiniAppShell.js НЕ трогаем (1143 строки, горячий): бейджи/названия таба идут через registry-контракт; если понадобится доступ к данным шелла, которых нет в ctx — согласовать расширение ctx отдельным микро-PR, не править switch базовых табов

**Тест-план:**
- [деньги, обяз.] Feature: accounts — сидим лоты/проводки через сервисы T02 → /v2/plan/accounts возвращает балансы, В ТОЧНОСТИ равные сумме ledger по субсчёту (int-центы, без float); лоты earliest-expiry-first; истёкшие лоты исключены; history cursor-пагинация стабильна и не течёт между участниками
- [деньги, обяз.] Feature: awards — суммы наград строго из PolicyVersion в центах; скачок Consultant→Bronze показывает ОБЕ награды Manager+Bronze earned (DEC-040); credited появляется только при реальном зачислении на БС
- [права, обяз. negative] флаг mh_plan_v2_miniapp OFF → все 6 эндпоинтов недоступны (ответ middleware feature.flag); лид (не member) с валидным initData → 404 на каждом эндпоинте; участник A не видит данных участника B (скоуп по initData); account вне os|ns|bs → 422; невалидный cursor → без 500
- [статусы] Feature: rank-progress — фикстурная сеть: текущий ранг/тир корректны; actuals варианта считают кандидатов из РАЗНЫХ корневых ветвей (BR-TREE-001: два кандидата одной ветви = одна ветвь); прогресс малой ветки PV совпадает с данными T05; 'ранг навсегда' — после падения объёмов ранг в ответе не понижается; снапшот квалификации (variant/qualifiers) отдаётся для достигнутого ранга
- [unit] мапперы read-сервисов: деньги всегда int-центы (ни одного float в JSON), PV — decimal-строка, даты ISO-8601 UTC; overview агрегирует ровно те же цифры, что детальные эндпоинты (нет второй правды)
- [unit] AwardsReadService: derive-логика по истории рангов idempotent; пустой конфиг наград → пустой список, не исключение
- [фронт] npm run lint + next build зелёные; проверка полноты локалей: каждый ключ planv2.* существует и в ru и в en (скрипт-сверка ключей); ручной смоук в Telegram: RU/EN переключение без пропавших строк, флаг OFF → таба нет, лид → таб не показан
- [пропорция] нагрузочных/e2e не требуется: T14 read-only, ни одной записи в ledger; основной риск — правдивость отображения денег и утечка чужих данных, он закрыт feature-тестами выше

**Вопросы к Гейту A:**
- Размещение в UI: оставить V1-вкладки 'доход/ранг' (mmRank/mmDashboard) рядом с новым табом 'Мой план V2' до cutover T15, или при включении флага mh_plan_v2_miniapp сразу скрывать V1-ранг-вкладку? (двойная правда о ранге на экране до cutover может путать партнёров)
- Детализация истории счетов: показывать партнёру каждую ledger-проводку с типом бонуса (полная прозрачность, но шумно и раскрывает механику начислений) или агрегировать по дню/типу бонуса? Влияет на контракт history-эндпоинта
- Секция 'Награды' до мерджа T10: показывать статусы, деривированные из истории рангов (может на дни разойтись с реальной ручной выплатой), или скрыть секцию отдельным под-флагом до T10?

**Допущения:**
- T02 предоставляет read-методы: балансы субсчетов участника, активные лоты с expires_at, историю движений субсчёта; коды счетов os|ns|bs; T14 не лезет в ledger_entries напрямую
- T05 предоставляет (или согласится добавить) breakdown/explain-режим квалификации: по каждому из 3 вариантов следующего статуса — требования, фактические счётчики с учётом разных корневых ветвей, PV малой ветки; T14 только проецирует, не пересчитывает
- Каталог статусов/тиров/наград читается из PolicyVersion-конфига T01 через сервисы T05 (актуальная версия на текущую дату)
- API отдаёт только машинные коды и числа — вся RU/EN локализация на фронте в src/locales/{ru,en}; en-каталог Resources/lang на бэке для T14 не нужен
- Один новый таб в blockCTabs с внутренними секциями (не три таба) — таб-бар уже содержит 5 базовых + 3 blockC вкладки
- Флаг mh_plan_v2_miniapp независим от движкового cutover-флага T15; UI можно выкатить тёмным (флаг OFF) до переключения расчёта
- Слот миграций T14 — 2026_07_12_1400xx; единственная миграция — сид фича-флага
- Деньги — integer USD-центы (курс 468 KZT=1 USD зашит в конфиг-параметры, фронт показывает только USD через usd()); PV — decimal
- Награды по решению владельца — деньгами USD на БС, выплата вручную; miniapp показывает статус, кнопок выплаты нет
- Фронтовой тест-раннер в проекте отсутствует — фронт покрывается lint/build + смоук, денежная логика тестируется на бэке

## T15 — Миграция прода и cutover: opening-миграция main→ОС, shadow/parity V1 vs V2, feature-flag переключение, rollback-план и PROD-гейт
Зависит от: T06, T07, T08, T09, T10, T11, T12

**Таблицы:**
- v2_parity_runs: id, status(pending|running|done|failed), scope json (диапазон периодов/событий), v1_total_cents bigint, v2_total_cents bigint, unexplained_delta_cents bigint, summary json (по типам бонусов), accepted_at timestamp nullable, accepted_by FK members nullable, started_at, finished_at, created_by FK members nullable
- v2_parity_diffs: id, run_id FK v2_parity_runs cascadeOnDelete, member_id FK members, bonus_type string(32), v1_amount_cents bigint, v2_amount_cents bigint, delta_cents bigint, classification string(24) (plan_change|data_issue|engine_defect|unexplained), note text nullable; index(run_id, member_id), index(run_id, classification)
- v2_cutover_state: id (единственная строка, guard в сервисе), phase string(16) (pre|shadow|migrated|live|rolled_back), shadow_enabled_at, migrated_at, live_at, rolled_back_at (все nullable timestamps), opening_balance_tx_id uuid nullable (tx_id reclass-группы в ledger_entries), rollback_tx_id uuid nullable, actor string nullable (кто запустил команду), checklist json (результаты прекондишенов: reconciliation, parity accepted, периоды, выводы), updated_at
- feature_flags (существующая, только сид-строки): mh_plan_v2 (авторитетный расчёт V2, enabled=false), mh_plan_v2_shadow (V2 dry-run рядом с V1, enabled=false)
- Без изменений схемы V1: ledger_entries, member_wallets, member_bonus_lines, member_earnings не альтерятся; opening-миграция — только новые проводки через счета/лоты T02 (их таблицы — контракт T02, T15 их не создаёт)

**Миграции (по порядку):**
- 2026_07_12_150000_create_v2_parity_runs_table.php
- 2026_07_12_150100_create_v2_parity_diffs_table.php
- 2026_07_12_150200_create_v2_cutover_state_table.php (+ insert единственной строки phase=pre)
- 2026_07_12_150300_seed_v2_cutover_feature_flags.php (insertOrIgnore mh_plan_v2 и mh_plan_v2_shadow, enabled=false, description; по паттерну deny-by-default C3)
- Слот timestamp T15: 2026_07_12_1500xx — не пересекается со слотами T01-T14; данные прода НЕ мигрируются Laravel-миграцией (только схема) — перенос балансов делает идемпотентная artisan-команда v2:cutover под advisory-lock, по спеке 05 §5 'signed migration transactions'

**Backend:**
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Cutover/V2Gate.php — единственная точка чтения флагов mh_plan_v2/mh_plan_v2_shadow поверх FeatureFlagService (методы calcV2(): bool, shadow(): bool); контракт для T06-T12: все V2-начисления гейтятся ТОЛЬКО через V2Gate, без собственных проверок флагов
- mh-calc-backend-main/Modules/Calculator/Services/ActivationService.php — ПРАВКА (общий файл): в activate() после acquireActivationLock() флаг-ветка: V2 on → V1 recompute() не вызывается (снапшот member_bonus_lines/member_earnings заморожен), событие уходит в V2-пайплайн T06-T12; shadow on → V1 работает как сейчас + V2 dry-run пишет diff; оба off → текущее поведение без изменений
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Cutover/OpeningBalanceMigrationService.php — идемпотентный перенос main-баланса → ОС: по каждому участнику с available_cents>0 reclass-группа проводок Dr member_available / Cr <ОС-счёт T02> одним tx_id, idempotency key 'v2migrate:opening:m{member_id}', создание opening-лота ОС через lot-API T02; held/clawback не трогаются (открытые заявки на вывод доживают по V1-пути); Σдо==Σпосле — инвариант
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Cutover/HistoryBackfillService.php — backfill фактов по историческим paid-заказам 5 участников: BV/PV-снапшоты в формат T03, PV-лоты бинара, накопленный personal PV → тир, прогон статусной лестницы T05; ДЕНЬГИ по истории НЕ проводятся (V1-начисления остаются как opening) — идемпотентно по order_id
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Cutover/ParityRunService.php — паритетный прогон: V1 CompensationEngine (read-only, как в recompute но без записи) vs V2-движок в dry-run (расчёт T06-T11 без posting) на тех же прод-данных; per-member/per-bonus diff в v2_parity_diffs с классификацией; итог и хэши в v2_parity_runs; строго без записи в ledger/wallets
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Cutover/LedgerReconciliationService.php — trial balance (Σdebit==Σcredit по tx_id и глобально) + сверка member_wallets с свёрткой ledger_entries + сверка лотов T02; используется как прекондишен cutover и как проверка после rollback
- mh-calc-backend-main/Modules/Calculator/ServicesV2/Cutover/CutoverStateService.php — state-machine единственной строки v2_cutover_state (pre→shadow→migrated→live / →rolled_back), чеклист прекондишенов: reconciliation delta=0, есть accepted parity run, нет заявок на вывод в processing, дата = граница half-month, флаги в согласованном состоянии
- mh-calc-backend-main/Modules/Calculator/Console/V2ParityRunCommand.php — artisan v2:parity-run {--from=} {--to=}: синхронный прогон (5 участников), выводит сводку и id отчёта
- mh-calc-backend-main/Modules/Calculator/Console/V2CutoverCommand.php — artisan v2:cutover --confirm: под ACTIVATION_LOCK_KEY в одной транзакции: чеклист → OpeningBalanceMigrationService → HistoryBackfillService → phase=migrated; затем отдельным явным шагом v2:cutover --go-live --confirm включает mh_plan_v2 (запускается ТОЛЬКО человеком на проде — PROD-гейт)
- mh-calc-backend-main/Modules/Calculator/Console/V2RollbackCommand.php — artisan v2:rollback --confirm: выключает mh_plan_v2 (V1 немедленно возобновляется), компенсирующая reclass-группа остатка ОС→member_available с ключами 'v2rollback:m{id}', phase=rolled_back, отчёт reconciliation; V2-проводки не удаляются (append-only)
- mh-calc-backend-main/Modules/Calculator/Http/Controllers/V2CutoverController.php — admin owner-only read: GET status (фаза+чеклист), GET parity-runs, GET parity-runs/{id} (diffs с фильтром по classification), POST parity-runs (запуск), POST parity-runs/{id}/accept (финансовый sign-off отчёта); сам cutover через HTTP НЕ доступен — только CLI
- mh-calc-backend-main/Modules/Calculator/Routes/api/v2_cutover.php — свой роут-файл по образцу feature_flags.php: группа admin, middleware web.admin + calculator.role:owner; + одна строка require в Routes/api.php
- mh-calc-backend-main/Modules/Calculator/Models/V2ParityRun.php, Models/V2ParityDiff.php, Models/V2CutoverState.php
- Регистрация: команды и DI-биндинги T15 — в CalculatorV2ServiceProvider (контракт T01/T02); если к старту T15 его нет — T15 создаёт его и подключает одной строкой из CalculatorServiceProvider::register(); расписания T15 не добавляет (все команды ручные)
- docs/runbooks/mh-v2-cutover.md — runbook PROD-гейта: пошаговый чеклист (deploy кода → shadow ≥1 полный half-month → parity accept → v2:cutover → go-live на границе периода → daily reconciliation), rollback-план с критериями отката и владельцем решения; deploy.yml не трогается
- mh-calc-backend-main/Modules/Calculator/Tests/Feature/V2CutoverTest.php, Tests/Feature/V2ParityRunTest.php, Tests/Unit/OpeningBalanceMigrationTest.php, Tests/Unit/LedgerReconciliationTest.php

**Общие файлы (риск конфликта):**
- mh-calc-backend-main/Modules/Calculator/Services/ActivationService.php — T15 вносит единственную флаг-ветку V1/V2/shadow; T03/T06/T07 тоже встраиваются в activate/markPaid-поток — договориться, что проверка флага живёт только здесь и только через V2Gate
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — одна строка require 'api/v2_cutover.php' (конфликт на одной строке со всеми T02-T14)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php / CalculatorV2ServiceProvider.php — регистрация команд v2:* рядом с джобами T04/T09
- feature_flags (таблица) — сид-миграции флагов делают несколько задач; ключи T15: mh_plan_v2, mh_plan_v2_shadow; insertOrIgnore по key
- Namespace idempotency-ключей ledger — T15 резервирует префиксы 'v2migrate:' и 'v2rollback:'; T12 (reversal) и T06-T10 не должны их использовать

**Риски конфликтов:**
- ActivationService.php — самый острый: T15 меняет вызов recompute(), T06/T07 вешают свои V2-хуки на тот же поток оплаты/активации. Митигация: T15 мержится ПОСЛЕДНИМ в волне (по depends_on так и выходит), контракт V2Gate объявить на Гейте A, чтобы T06-T12 не наплодили собственных флаг-проверок
- Контракт dry-run у движка T06-T11: parity требует расчёта БЕЗ posting. Если T06-T11 не предусмотрят режим preview/no-posting, T15 придётся оборачивать их сервисы — зафиксировать требование 'calculate отделён от post' в их контрактах сейчас
- T02: имена счетов ОС/НС/БС и lot-API — opening-миграция пишет через них; если T02 переименует константы/сигнатуры после старта T15 — переделка reclass-кода. Заморозить константы счетов T02 на Гейте A
- T04: cutover на границе half-month зависит от календаря периодов T04 (1/16 UTC) — прекондишен T15 читает состояние периодов T04; несогласованность статусов open/closed сорвёт чеклист
- T13: если админка захочет страницу cutover/parity — она потребитель JSON-контракта T15; контракт ответов V2CutoverController заморозить, страницу делать после мерджа T15
- T12: reversal по pre-cutover заказам после go-live затрагивает V1-начисления, вошедшие в opening-баланс ОС — сценарий 'ручной возврат старого заказа после cutover' должен идти корректирующей проводкой по ОС (правило T12), задокументировать в runbook
- Timestamp-слоты миграций: T15 занимает 2026_07_12_1500xx — проверить в merge-train, что T13/T14/T16 не залезли в этот диапазон

**Тест-план:**
- ДЕНЬГИ (обязательные): идемпотентность opening-миграции — v2:cutover дважды → ровно один набор reclass-проводок (ключи v2migrate:opening:m{id}), member_wallets согласован с ledger
- ДЕНЬГИ: инвариант сохранения суммы — Σ available_cents до миграции == Σ остатков ОС после; held_cents/clawback_debt_cents не изменились; trial balance (Σdebit==Σcredit) == 0 после reclass
- ДЕНЬГИ: непрерывность выводов — открытая заявка на вывод (held) переживает миграцию и доводится до paid по V1-пути; новая заявка после go-live списывает только с ОС (контракт T02)
- ДЕНЬГИ: rollback — go-live → v2:rollback → следующий пересчёт V1 не задваивает начисления (разные namespace ключей), компенсирующая группа ОС→main сбалансирована, reconciliation=0; повторный rollback — no-op
- Parity/shadow строго read-only: assert count(ledger_entries) и все wallet-балансы не изменились за прогон; shadow-режим при оплате заказа постит только V1
- Диспетчер флагов: оба off → текущее поведение (регресс V1-тестов зелёный); shadow on → V1 постит + строки diff появляются; mh_plan_v2 on → V1 recompute не вызывается, member_bonus_lines/member_earnings НЕ стираются
- Golden-фикстура '5 участников прода' (балансы, оплаченные заказы, без рангов): полный цикл shadow → parity accept → cutover → go-live → rollback; после backfill статусы/тиры соответствуют правилам T05, unexplained delta parity == 0
- Negative прекондишены: reconciliation delta != 0 → v2:cutover abort без каких-либо проводок; нет accepted parity run → abort; phase != migrated → --go-live abort; повторный --go-live — no-op
- Negative права: все /admin/v2-cutover/* → 401 без токена, 403 не-owner; из cabinet (telegram.auth) недоступны; POST accept пишет актора в admin_audit_log
- Конкуренция: миграция держит ACTIVATION_LOCK_KEY — конкурентная активация ждёт коммита cutover-транзакции, interleave-проводок нет (тест с двумя соединениями)
- Классификация diff: расхождения из-за смены плана (4 ранга vs 12 статусов) помечаются plan_change и не блокируют accept; unexplained > 0 блокирует accept отчёта

**Вопросы к Гейту A:**
- Opening-баланс на ОС: подпадает ли перенесённый main-баланс под сгорание через 1 год (обычный кредит-лот ОС по DEC-015) или это бессрочный opening-лот? Деньги 5 участников — нужно явное решение
- Retro-PV при backfill: начислять ли накопленный personal PV/тир и статусы по историческим оплаченным заказам 5 участников (тогда у кого-то тир может быть выше START и реферальная L2 0/5/8% изменится), или все стартуют с нуля как CLIENT/CONSULTANT?
- Момент go-live: фиксируем ли правило 'только на границе half-month (1-е или 16-е 00:00 UTC)', чтобы ни один период не считался смешанно V1/V2? Рекомендация — да, закрепить в runbook как жёсткий прекондишен
- Sign-off parity: кто принимает отчёт расхождений перед PROD-гейтом — только владелец (owner в системе) или достаточно оператора-админа? Рекомендация — owner, кнопка accept пишется в аудит

**Допущения:**
- Контракт T02: ОС/НС/БС — новые account_type в существующей ledger_entries + таблицы лотов; T15 постит reclass/opening только через сервисы T02, своих счетов не заводит
- Движки T06-T11 отделяют calculate от post (dry-run возможен) — требование к их контрактам; иначе T15 добавляет no-posting обёртку
- Бонусы за pre-cutover заказы V2 НЕ перепроводит: V1-начисления остаются как opening-баланс ОС; parity — диагностика, не источник проводок (подтвердить на Гейте A вместе с вопросами)
- 'Паритет' = классифицированный diff, а не тождество: планы различаются by design (4 ранга vs 12 статусов, новые бонусы); критерий приёмки — unexplained delta = 0 (gap-анализ Phase 3)
- Флаги mh_plan_v2/mh_plan_v2_shadow — через существующий FeatureFlagService (deny-by-default, кэш 60с приемлем: авторитетная проверка идёт под advisory-lock в транзакции); все задачи V2 читают их только через V2Gate T15
- После go-live V1 заморожен, но не удаляется: Domain V1 не вызывается, member_bonus_lines/member_earnings остаются read-моделью; демонтаж V1 — вне скоупа T15 (после T16)
- Cutover/rollback — только artisan-команды, запускаемые человеком на проде (PROD-гейт); admin-API — read-only статус/отчёты + accept; .github/workflows/deploy.yml не меняется
- Фронта в T15 нет: отчёты доступны через admin JSON и вывод CLI; страницу поверх контракта при желании добавит T13 отдельной задачей
- Прод крошечный (5 участников, рангов нет — из триажа): parity синхронный, без шардирования/lease из спеки 06; полный пересчёт сети допустим
- Ранг навсегда (DEC-020) упрощает миграцию: маппинг рангов сводится к прогону лестницы T05 по backfill-данным, механики понижения/пере-подтверждения нет

## T16 — Маркетинговая политика v3.0 — партнёрский документ (MD+HTML+PDF) по фактически реализованному плану V2, с построчной сверкой
Зависит от: T15

**Таблицы:**
- Нет новых таблиц — задача документационная. Источник фактов (read-only): policy_versions / конфиг-сид T01 (ставки, пороги, капы, награды в USD-центах), таблицы счетов/лотов T02, периодов T04, отчёт паритетного прогона T15.

**Backend:**
- DOCS (основной артефакт): docs/marketing-policy/marketing-policy-izigo.md — полная переработка v2.0 -> v3.0. Структура по образцу v2.0 (шапка версия/дата/валюта, содержание, 1 «О документе», 2 «Отказ от гарантий дохода», 3 «Право Компании вносить изменения» — теперь через PolicyVersion c valid_from/valid_to вместо plan_settings), далее новые/переписанные разделы: термины (BV в USD и PV баллы раздельно, снапшот на заказ; счета ОС/НС/БС; кредит-лоты 1 год; тиры; расчётные периоды); тарифы активации и тиры START/BUSINESS/ELITE по накопленному личному PV (100–199/200–599/600+, снапшот, без даунгрейда); путь CLIENT (покупка >=100 PV) -> CONSULTANT (grace 30 календарных дней при появлении личного реферала, конец 30-го дня, DEC-026); бинарное дерево и PV-лоты L/R (все binary descendants, DEC-055; matching min(free L, free R); carryover без сгорания); лестница 12 статусов CLIENT..VICE_PRESIDENT с малой ветвью 1k/3k/8k/20k/60k/150k/380k/760k/1.5M/3M PV и 3 вариантами квалификации (вариант 1 exact-rank, варианты 2–3 «и выше», кандидаты из разных корневых реферальных ветвей BR-TREE-001); «ранг навсегда» (DEC-020, без понижения и переподтверждения); бонус 1 — структурная премия 5–9% от BV matched PV с месячными/полумесячными капами по статусу в USD (500..40000), начисление на НС, перевод НС->ОС 1-го и 16-го, неиспользованный лимит не переносится; бонус 2 — реферальная премия 10% L1 / 0-5-8% L2 по тиру получателя, на ОС сразу, платится ВСЕГДА независимо от тира/статуса покупателя (скидка MH не применяется — решение владельца); бонус 3 — лидерский START 10% / BUSINESS 15% / ELITE 20/10/5/3/1/1/1% глубиной 1–7, компрессия «минус два статуса» (DEC-030), база — фактически выплаченная структурная премия даунлайна ПОСЛЕ капов и 60%-калибровки (DEC-029), на ОС; бонус 4 — глобальный: пулы 1/0.75/0.5/0.5/0.25% месячного глобального BV для DIRECTOR..VP, доли floor(PV реф.дерева/base), max 2 доли, cap 25% пула на участника, остаток компании (DEC-032/034), накопление ежемесячно/выплата квартально на ОС; квалификационные награды деньгами USD на БС: Manager 100 / Bronze 200 / Silver 300 / Gold 500 / Platinum 1500 / Director 2500 / Pearl 20000 / Sapphire 35000 / Diamond 53000 / VP 150000 (3 этапа по 50000, этапы 2–3 по квалификациям глобального), при скачке — все пройденные ступени (DEC-040), выплата вручную; раздел «Калибровка выплат 60%» — прозрачное описание пропорционального scale-down всех бонусов периода при превышении 60% BV-оборота, никогда вверх (DEC-014); счета: ОС/НС/БС, срок жизни кредита ОС 1 год, просроченный остаток -> БС, оплата заказа с ОС <= 70%, вывод только с ОС; расчётные периоды half-month (1–15/16–конец, UTC), month, quarter, запрет изменения закрытых периодов; возвраты/сторно (reversal, достигнутые ранги/награды не отзываются, DEC-027); кошелёк/пополнение/вывод и KYC — перенести из v2.0 §17–18 с правкой на ОС; общие положения; фиксация «годовая подписка не применяется» (DEC-004). УДАЛИТЬ/переписать наследие v2.0: «1 PV = 1 USD» как единственная модель, 4 ранга, бинарный 5% от PV малой ветки, тиры по пакетам
- DOCS: docs/marketing-policy/marketing-policy-izigo.html — регенерация в том же шаблоне v2.0 (A4 page-model, aurora/TON стили, @media print, .page-блоки); контент 1:1 с MD; проверить page-break на новых больших таблицах (12 статусов, капы, награды)
- DOCS: docs/marketing-policy/marketing-policy-izigo.pdf — печать финального HTML headless Chrome/Edge (как v2.0: 'Edge headless'), проверить полноту страниц и цветопередачу print-color-adjust
- DOCS: docs/marketing-policy/v3-traceability.md — построчная сверка с V2-движком: таблица «утверждение документа -> ключ конфига PolicyVersion (T01 seed) / модуль ServicesV2 -> DEC-решение -> статус OK/расхождение»; покрыть ВСЕ числовые параметры (пороги статусов, ставки 5–9%, капы USD, тир-границы, награды, пулы, max=2, cap 25%, лимит 70%, срок 1 год, даты 1/16, порог 60%); вход для сверки — также отчёт паритетного прогона T15
- OPTIONAL (если владелец захочет автоматическую сверку): mh-calc-backend-main/Modules/Calculator/Tests/Unit/V2/PolicyDocumentFactsTest.php — фикстура policy-v3-facts.json с числами из документа, ассерты против активного PolicyVersion-конфига; НЕ трогает продовый код, читает конфиг T01 read-only

**Общие файлы (риск конфликта):**
- docs/marketing-policy/marketing-policy-izigo.md|.html|.pdf — перезапись канонических файлов v2.0 (v2.0 остаётся в git-истории, коммиты 6f67b87/d7a46b9; вариант с archive/ — вопрос владельцу)
- mh-calc-backend-main/resources/knowledge-base/marketing-plan.md — KB AI-ассистента, сейчас описывает план V1 (4 ранга, бинарный 5%); станет ложью после cutover — обновление в скоупе T16 или отдельно (вопрос владельцу); переключать строго синхронно с прод-cutover T15
- docs/specs/2026-07-12-mh-full-plan-dec-triage.md и roadmap.yml — только чтение, не править

**Риски конфликтов:**
- Файловых конфликтов с T01–T15 практически нет: docs/marketing-policy/* больше никто в блоке не трогает, backend/frontend не изменяются
- СОДЕРЖАТЕЛЬНАЯ зависимость — главный риск: документ можно финализировать только ПОСЛЕ заморозки сид-значений PolicyVersion (T01) в release-ветке и после отчёта паритетного прогона T15; любая поздняя правка ставки/капа в T01–T12 инвалидирует сверку — T16 запускать последним в merge train, после code freeze release/mh-full-plan
- Дата вступления в силу v3.0 = дата прод-cutover (PROD-гейт T15, решает человек) — документ нельзя публиковать партнёрам с датой раньше фактического переключения feature-flag; риск рассинхрона «документ уже v3.0, движок ещё V1»
- resources/knowledge-base/marketing-plan.md: если T14 (Mini App) или кто-то ещё правит тексты ассистента параллельно — согласовать; сейчас пересечений в роадмапе нет
- PDF — бинарник: любой параллельный коммит в docs/marketing-policy даст неразрешимый merge-конфликт; коммитить MD+HTML+PDF одним атомарным коммитом в конце

**Тест-план:**
- ОБЯЗАТЕЛЬНО (деньги в тексте): 100%-ная числовая сверка через v3-traceability.md — каждый параметр документа сверен с фактическим сидом PolicyVersion (не со спекой!): пороги малой ветки 10 значений, ставки structure 5/5/5/5/6/6/7/7/8/8/9%, капы месячные/полумесячные в USD (включая Pearl = 25 000 USD по DEC-039, а не опечатку PPTX), тир-границы 100–199/200–599/600+, реферальные 10 и 0/5/8%, лидерская матрица 10/15 и 20/10/5/3/1/1/1 глубина 7, пулы 1/0.75/0.5/0.5/0.25%, max долей 2, cap 25%, награды 100/200/300/500/1500/2500/20000/35000/53000/150000 USD, лимит оплаты с ОС 70%, срок лота 1 год, переводы 1/16, порог калибровки 60%; ноль расхождений — критерий приёмки
- NEGATIVE-чек (отсутствие ложных обещаний): grep-чеклист — в документе НЕТ: годовой подписки как обязанности (только «не применяется»), скидки MH 10%, понижения/переподтверждения ранга, paid-rank, scale-up при калибровке, автоматических он-чейн выплат, гарантий дохода; НЕТ остатков v2.0: «1 PV = 1 USD» как модель бонусов, 4 ранга, «бинарный 5% от PV малой ветки», тиры по пакетам Bronze/Silver/Gold
- Сверка формулировок с решениями владельца: DEC-004/014/020/029/032/040/055 процитированы корректно (ранг навсегда; полная 60%-калибровка со scale-down; база лидерского — фактически выплаченное; все награды при скачке; всё бинар-дерево)
- Кросс-проверка против отчёта паритетного прогона T15: примеры расчётов в документе (если приводятся) совпадают с фактическим выводом V2-движка на тестовых кейсах
- Рендер-приёмка: HTML открывается, A4-страницы не рвут таблицы посередине строк, оглавление-якоря работают; PDF регенерирован из финального HTML, страницы полные, цвета печатаются (print-color-adjust)
- Опционально: PolicyDocumentFactsTest.php зелёный (facts.json == активный PolicyVersion) — защита от будущего дрейфа конфига относительно опубликованного документа
- Финальная вычитка владельцем (юридические формулировки, дисклеймеры) до публикации — человеческий гейт

**Вопросы к Гейту A:**
- Дата вступления в силу v3.0: ставить фактическую дату прод-cutover (T15, PROD-гейт) — т.е. финализировать шапку документа в момент переключения, или публикуем заранее с плановой датой? (рекомендация: placeholder до cutover, финализация датой переключения)
- Файловая политика версий: перезаписать канонические marketing-policy-izigo.* (v2.0 остаётся в git-истории, как делали v1->v2) или завести docs/marketing-policy/archive/ с копией v2.0? (рекомендация: перезапись, git-история достаточна)
- Входит ли в T16 обновление KB AI-ассистента (mh-calc-backend-main/resources/knowledge-base/marketing-plan.md) — сейчас он рассказывает партнёрам план V1 и станет ложью после cutover? Если да — синхронизировать с моментом cutover (рекомендация: да, отдельным коммитом, применяемым при cutover)
- Автошип (§7 v2.0): остаётся ли в v3.0 как платформенная механика (покупки автошипа порождают BV/PV-лоты как обычные заказы), или убирается из политики? Роадмап V2 его не упоминает
- Язык документа: только RU (как v2.0) или RU+EN (Mini App T14 двуязычный)? EN-версия удваивает объём сверки
- Делать ли автоматический guard-тест PolicyDocumentFactsTest (facts.json против PolicyVersion) — плюс: защита от дрейфа конфига после публикации; минус: каждая правка плана владельцем через админку потребует правки документа/фикстуры

**Допущения:**
- T16 — чисто документационная задача: ноль миграций, ноль изменений backend/frontend кода (кроме опционального read-only теста-сверки); запретные зоны не затрагиваются
- Источник правды для всех чисел — фактический сид PolicyVersion (T01) и поведение V2-движка, подтверждённые паритетным прогоном T15; спека PPTX/KZT — только для трассировки, все суммы в документе в USD по фиксу 468 (Pearl cap 25 000 USD по DEC-039, награды по T10 с поправками DEC-038)
- Шаблон оформления HTML v2.0 (A4 .page-модель, aurora/TON палитра) переиспользуется; PDF — headless Chrome/Edge печать финального HTML, как в коммите d7a46b9
- 12 статусов и матрица квалификаций — по 02_Business_Rules §7 (BR-RANK-001) в интерпретации T05: вариант 1 exact-rank, варианты 2–3 «и выше», кандидаты из разных корневых реферальных ветвей; TRAINEE/«Стажёр» рангом не является (DEC-056)
- Решения владельца от 2026-07-12 нормативны и превалируют над спекой: без подписки, ранг навсегда, полная 60%-калибровка, база лидерского — фактически выплаченное, все награды при скачке, всё бинар-дерево, награды деньгами, без скидки MH (referral_stop_at_elite=false фиксируется в документе как «реферальная премия платится всегда»)
- Раздел о 60%-калибровке раскрывается партнёрам явно (прозрачность механизма, уменьшающего выплаты) — формулировку утверждает владелец на финальной вычитке
- Разделы v2.0 §2 (отказ от гарантий), §17–18 (кошелёк/вывод/KYC) переносятся с минимальной правкой под ОС/НС/БС; платёжный контур TON Pay/ручные выплаты не меняется
- T16 выполняется последним, после merge всех T01–T15 в release/mh-full-plan и code freeze; коммит с [skip ci] по образцу предыдущих doc-коммитов

---

## Сводка вопросов к владельцу (все задачи)

- **T01**: Тиры vs текущие тарифы IziGo: спека/роадмап задают тиры по personal PV 100-199/200-599/600+, но действующие тарифы IziGo — Bronze 90 / Silver 180 / Gold 540 PV (DEC-010 триажа), то есть покупатель Bronze не дотягивает даже до START. Что правим: PV тарифов до 100/200/600 или сидируем пороги тиров 90/180/540? (влияет на сид T01 и на T05/T07)
- **T01**: Подтверждение значения флага referral_stop_at_elite в сиде: роадмап T07 выносит вкл/выкл на Гейт A; T01 сидирует дефолт TRUE (покупки ELITE-покупателя не генерят реферальную) — оставить TRUE?
- **T02**: Срок нового БС-лота после переноса просроченного остатка ОС→БС: спека оставила BLOCKER (BR-ACC-004), триаж DEC-015 его не закрыл. Предлагаю: 1 год с даты переноса, параметр конфига accounts.bs_lot_lifetime_days. Подтвердить?
- **T02**: Допускается ли оплата заказа на 100% со счетов (ОС ≤70% + БС на остаток), т.е. заказ вообще без TON-платежа? По спеке БС используется «для покупок на общих условиях» без лимита — предлагаю да; но это создаёт путь покупки тарифа без внешних денег, что влияет на экономику (бонусы с BV, оплаченного бонусными деньгами). Подтвердить или ограничить (например, суммарно счетами ≤70%)?
- **T02**: НС→ОС 1/16 до появления периодов T04: переводить весь остаток НС на момент джоба (просто) или сразу закладывать gating «только по finalized half-month» (спека 06)? Предлагаю: в T02 — весь остаток (НС пополняется только T06 при закрытии периода, к 1/16 остаток и так финализирован), gating добавит T04. Возражения?
- **T03**: BV тарифов в USD-центах: подтвердить значение BV для каждого текущего тарифа (Bronze 90PV / Silver 180PV / Gold 540PV). Дефолт плана: BV = 100% цены тарифа (bv_usd_cents NULL => price). Если BV != цене (как в легаси, где 100PV=42120 KZT при иной цене) — дать таблицу BV по тарифам до включения флага.
- **T03**: Нужен ли LIVE-провизорный матчинг (пересчёт min(L,R) при каждой оплате) для карточки бинара в Mini App, или матчинг запускается ТОЛЬКО при закрытии half-month (T04/T06)? T03 отдаёт runMatching как сервис + ручной admin-запуск; live-режим добавил бы нагрузку и provisional-матчи, которые надо уметь отменять — по умолчанию НЕ включаем.
- **T03**: Перемещение узла админом в бинар-дереве (PlacementAdminService) ПОСЛЕ создания лотов: существующие лоты остаются у прежних предков с прежними сторонами (provenance неизменен), новые заказы идут по новому пути. Подтвердить, что ретро-перенос лотов не требуется.
- **T04**: Время НС->ОС относительно 60%-калибровки полумесячного периода (OPEN-POOL-02): подтвердите порядок 'перевод 1/16 выполняется ТОЛЬКО из уже закрытого half-month (после капов и 60%-калибровки этого периода)'. Альтернатива 'перевод до калибровки + корректировка задним числом' в план не заложена и противоречит запрету изменения закрытых сумм
- **T04**: Reopen закрытых периодов: план НЕ предусматривает админский reopen вовсе (только корректирующие проводки T12, спека же допускает REOPENED с approval). Подтвердите, что reopen не нужен — это упрощает инварианты денег
- **T04**: Время запуска джобов 00:01/00:10/00:20/00:30/00:40 UTC (границы периодов — UTC по роадмапу): ок, или привязать запуски к утру Алматы (границы остаются UTC)? Влияет только на задержку начислений, не на суммы
- **T04**: Фиксация контракта на Гейте A между параллельными T02/T04: интерфейс NsToOsTransferHandler::transferForClosedHalfMonth(CalcPeriod, string $windowKey) реализует T02 — нужно явно передать сигнатуру в план T02, иначе волна разъедется
- **T05**: Тарифы vs пороги: Bronze = 90 PV, а CLIENT/активация требует >=100 PV — покупатель одного Bronze НЕ становится CLIENT и не получает тир. Что делаем: (а) поднять PV Bronze до 100, (б) снизить порог CLIENT/START до 90, (в) оставить как есть (Bronze не активирует)? Связано: триаж DEC-010 определял тир как «максимальный купленный тариф», а роадмап T05 — «по накопленному personal PV» (тогда Silver 180=START, Gold 540=BUSINESS, ELITE только суммой покупок). Планирую по роадмапу (накопленный PV) — подтвердите
- **T05**: Comparator варианта 1: роадмап фиксирует «вариант 1 exact-rank», но триаж DEC-022 (SPEC_DEFAULT) принял «at least (и выше)» для всех вариантов. Реализую конфигом per-variant с дефолтом по роадмапу (v1=exact, v2/v3=at_least) — подтвердите дефолт, это влияет на скорость карьерного роста
- **T05**: Grace-старт: спека привязывает 30 дней к «бинарному утверждению» (BR-REG-003, ручное утверждение спонсором / автоплейсмент через 7 дней), но в IziGo плейсмент происходит сразу при создании участника без ручного гейта. Планирую grace от момента достижения CLIENT (= активация первым заказом >=100 PV). Ок? (Альтернатива — вводить в V2 ручное утверждение плейсмента, это заметный доп. скоуп)
- **T06**: Судьба matched PV при недостаточном/исчерпанном денежном капе (спека ЯВНО оставила открытым вместе с DEC-017/018, триаж не закрыл): (А) матчить весь min(L,R), платить до капа, весь matched PV сгорает (буквальный псевдокод спеки) или (Б) матчить PV только в объёме, покрываемом остатком капа (maxBV = remaining_cap / rate), остальное остаётся в carryover. Рекомендация: Б — согласуется с решением «carryover без сгорания» (T03) и щадит партнёра; влияет на контракт matching API T03 (нужен параметр-лимит)
- **T06**: Лаг НС->ОС для премии окна: закрытие H1/H2 и перевод НС->ОС происходят в одни даты (1-е/16-е). Премия, начисленная на НС при закрытии окна, переводится на ОС тем же числом сразу после закрытия (НС фактически транзитный, деньги доступны сразу) или только следующей датой 1/16 (клиринговый лаг ~15 дней)? Формулировка PPTX «НС -> ОС 1-го/16-го» допускает оба чтения; это контракт порядка джобов T02/T04, но семантику начисления T06 надо зафиксировать до кодинга. Рекомендация: перевод следующей датой (лаг 15 дней) — иначе НС не имеет смысла как счёт
- **T07**: Гейт A (заложено роадмапом): referral_stop_at_elite на старте прода — оставить TRUE (покупки ELITE-покупателя реферальную НЕ генерят, как в спеке; скидки MH при этом нет вообще — ап-лайн ELITE-покупателя не получает ничего) или выключить (FALSE: реферальная платится со всех покупок, включая ELITE)? Дефолт в коде — TRUE.
- **T07**: Получатель без тира (накопленный personal PV < 100, например только Bronze 90 PV по DEC-010): реферальную L1 10% платить всё равно или не платить вовсе (explain-запись no_tier без денег)? Таблица BR-TIER-001 начинается со START≥100 PV; рекомендация — не платить до достижения START, но это влияет на деньги текущих 5 участников.
- **T08**: 60%-числитель vs лидерский (стык DEC-014/029/053): порядок DEC-053 ставит расчёт лидерского ПОСЛЕ 60%-пула, значит лидерский того же периода сам НЕ входит в числитель калибровки этого периода (иначе цикл, о котором предупреждает 03_Calculation_Engine §11). Но решение DEC-014 говорит «включены все бонусы». Подтвердите: лидерский исключается из 60%-базы своего периода (только отражается в отчёте), без итеративного пересчёта?
- **T08**: DEC-030: подтвердите финальную семантику — БЛОК без компрессии (заблокированный партнёр и весь его subtree не порождают выплату получателю, уровень не «подтягивается» выше), порог = разница ordinal >= 3 (Director: Sapphire платится, Diamond+ блок), порог — параметр PolicyVersion. Формулировка роадмапа «компрессия» трактуется именно так?
- **T08**: Rank-gap проверять относительно ранга источника И промежуточных узлов пути (subtree-блок, как в триаже) — или только самого источника премии? Планируем «весь путь включая источник» (совпадает с V1 hasHigherRankInChain и триажем); нужно явное «ок», это деньги.
- **T09**: Входит ли ЛИЧНЫЙ месячный PV партнёра в «PV реферального дерева» для расчёта долей, или только PV даунлайна по sponsor-дереву? (Спека говорит referral-tree PV без уточнения; предлагаю default: включать личный PV, конфиг-флаг include_personal_pv=true)
- **T09**: База global BV месяца: ВСЕ оплаченные заказы компании (включая покупки лидов/клиентов без ранга и собственные заказы директоров) минус reversals? (Спека: eligible_company_bv без определения eligible; предлагаю default: все PAID минус reversed)
- **T09**: Квартальное начисление на ОС выполняется автоматически при закрытии квартала (сам вывод денег — как весь контур, вручную), или начисление тоже требует ручного подтверждения админа перед проводкой? (Предлагаю: автоматическая проводка на ОС, вывод вручную)
- **T10**: Годичный expiry БС-лотов (BR-ACC-003) применять ли к award-кредитам? Если да — невыплаченная вручную награда (напр. VP 150000 USD) сгорит через год ожидания ручной выплаты. Предлагаемый дефолт: award-лоты на БС БЕЗ auto-expiry (expires_at = null в лотах T02), сгорание только ручным forfeit админа
- **T10**: Входят ли единоразовые квалификационные награды в базу «все бонусы» 60%-калибровки (DEC-014)? Граф роадмапа говорит НЕТ (T11 не зависит от T10, награды event-time вне периода) — но формулировка владельца «включены все бонусы» допускает иное прочтение; крупная награда (Pearl+) способна съесть весь 60%-пул периода. Предлагаемый дефолт: НЕ включать (ручной гейт выплаты = контроль компании)
- **T11**: Гранулярность калибровки: спека однозначно даёт МЕСЯЦ (payout-pool-reconcile внутри month-close, 06 §7.1/7.4), но структурная — полумесячная с переводом НС→ОС 1/16, т.е. H1 к моменту калибровки уже переведена. Подтвердить: месяц + provisional-перевод H1 с корректировкой на month-close (вариант спеки 06 §7.4)? Альтернатива — держать H1 в НС до закрытия месяца, но это меняет контракт T06 «перевод 1/16».
- **T11**: Состав числителя: включать ли единоразовые квалификационные награды T10 (БС, до 150k USD) в 60%-пул? DEC-014 говорит «все бонусы», но T11 по роадмапу не зависит от T10, а награда VP разово пробьёт любой месячный пул. Рекомендация: исключить по умолчанию, управлять конфигом payout_pool.included_bonus_kinds.
- **T11**: Реферальная при f<1: корректирующий дебет ОС может увести участника в минус, если ОС уже потрачена/выведена. Разрешить уход в clawback_debt (существующий механизм ledger) или ограничивать adjustment доступным остатком (недобор — за счёт компании)? Рекомендация: clawback_debt.
- **T12**: Частичные возвраты в первой версии: поддерживать line-level partial (qty по позициям) или ограничиться только полным возвратом заказа? DEC-012 говорит, что кейс редкий и ручной — full-only заметно дешевле; спека требует partial. Рекомендация: заложить схему под partial (return_lines), в UI первой версии дать только full
- **T12**: Утверждение корректировок закрытых периодов: достаточно ли одной owner-роли (админ один), или нужен four-eyes из спеки §7 (создал один — утвердил другой)? Рекомендация: одна owner-роль + обязательный reason + audit_log
- **T12**: Глобальный бонус: возврат, уменьшающий пул уже закрытого МЕСЯЦА до квартальной выплаты — пересчитывать доли всех участников месяца (корректировка каждому) или корректировать только сторону компании, а после квартальной выплаты — всегда ручное решение? Рекомендация: до выплаты — уменьшить снапшот пула одной корректировкой, доли не пересчитывать; после выплаты — только ручные proposed-корректировки
- **T12**: Подтвердить: возврат денег покупателю (USDT) полностью ВНЕ системы (админ платит руками), система только фиксирует факт и сторнирует внутренние начисления — покупателю на ОС ничего не зачисляется?
- **T13**: Периоды из админки: строго read-only (закрытие — только джобы T04, рекомендация) или нужна кнопка ручного запуска закрытия/REOPENED из спеки 05 §4.8? Роадмап говорит «запрет изменения закрытых периодов» — предлагаю read-only в T13.
- **T13**: Активация PolicyVersion: спека требует four-eyes approve (draft→validate→approve→activate), но в IziGo фактически один owner. Упрощаем до owner-activate с confirm и полным аудитом (рекомендация) или делаем двухшаговый approve+activate «на вырост»?
- **T13**: Очередь наград — граница T13/T10: T13 делает UI+endpoint mark-paid, делегирующий в интерфейс, реализацию биндит T10 (рекомендация). Подтвердить, что T10 не заводит собственный admin-endpoint, и нужен ли админу дополнительно hold/forfeit в первой версии.
- **T13**: Контракты таблиц не-зависимостей: подтвердить на Гейте A имена/форму v2_period_calibrations (T11) и v2_rank_reward_entitlements (T10), т.к. T13 строит против них read-страницы до их реализации.
- **T13**: UX редактора конфига: первая версия — raw JSON с серверной валидацией и diff в аудите (рекомендация, быстро и безопасно) или структурированная форма по разделам (статусы/ставки/капы/награды — заметно дороже)?
- **T14**: Размещение в UI: оставить V1-вкладки 'доход/ранг' (mmRank/mmDashboard) рядом с новым табом 'Мой план V2' до cutover T15, или при включении флага mh_plan_v2_miniapp сразу скрывать V1-ранг-вкладку? (двойная правда о ранге на экране до cutover может путать партнёров)
- **T14**: Детализация истории счетов: показывать партнёру каждую ledger-проводку с типом бонуса (полная прозрачность, но шумно и раскрывает механику начислений) или агрегировать по дню/типу бонуса? Влияет на контракт history-эндпоинта
- **T14**: Секция 'Награды' до мерджа T10: показывать статусы, деривированные из истории рангов (может на дни разойтись с реальной ручной выплатой), или скрыть секцию отдельным под-флагом до T10?
- **T15**: Opening-баланс на ОС: подпадает ли перенесённый main-баланс под сгорание через 1 год (обычный кредит-лот ОС по DEC-015) или это бессрочный opening-лот? Деньги 5 участников — нужно явное решение
- **T15**: Retro-PV при backfill: начислять ли накопленный personal PV/тир и статусы по историческим оплаченным заказам 5 участников (тогда у кого-то тир может быть выше START и реферальная L2 0/5/8% изменится), или все стартуют с нуля как CLIENT/CONSULTANT?
- **T15**: Момент go-live: фиксируем ли правило 'только на границе half-month (1-е или 16-е 00:00 UTC)', чтобы ни один период не считался смешанно V1/V2? Рекомендация — да, закрепить в runbook как жёсткий прекондишен
- **T15**: Sign-off parity: кто принимает отчёт расхождений перед PROD-гейтом — только владелец (owner в системе) или достаточно оператора-админа? Рекомендация — owner, кнопка accept пишется в аудит
- **T16**: Дата вступления в силу v3.0: ставить фактическую дату прод-cutover (T15, PROD-гейт) — т.е. финализировать шапку документа в момент переключения, или публикуем заранее с плановой датой? (рекомендация: placeholder до cutover, финализация датой переключения)
- **T16**: Файловая политика версий: перезаписать канонические marketing-policy-izigo.* (v2.0 остаётся в git-истории, как делали v1->v2) или завести docs/marketing-policy/archive/ с копией v2.0? (рекомендация: перезапись, git-история достаточна)
- **T16**: Входит ли в T16 обновление KB AI-ассистента (mh-calc-backend-main/resources/knowledge-base/marketing-plan.md) — сейчас он рассказывает партнёрам план V1 и станет ложью после cutover? Если да — синхронизировать с моментом cutover (рекомендация: да, отдельным коммитом, применяемым при cutover)
- **T16**: Автошип (§7 v2.0): остаётся ли в v3.0 как платформенная механика (покупки автошипа порождают BV/PV-лоты как обычные заказы), или убирается из политики? Роадмап V2 его не упоминает
- **T16**: Язык документа: только RU (как v2.0) или RU+EN (Mini App T14 двуязычный)? EN-версия удваивает объём сверки
- **T16**: Делать ли автоматический guard-тест PolicyDocumentFactsTest (facts.json против PolicyVersion) — плюс: защита от дрейфа конфига после публикации; минус: каждая правка плана владельцем через админку потребует правки документа/фикстуры
