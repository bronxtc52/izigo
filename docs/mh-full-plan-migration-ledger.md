# mh-full-plan — Migration Ledger + журнал волн

Блок `mh-full-plan`, интеграционная ветка `release/mh-full-plan` (НЕ main).
Обязательные документы для всех кодинг-агентов:

- планы: `docs/specs/2026-07-12-mh-full-plan-gateA-plans.md`
- **ПОПРАВКИ (приоритет над планами):** `docs/specs/2026-07-12-mh-full-plan-gateA-amendments.md`
- решения владельца: `docs/specs/2026-07-12-mh-full-plan-dec-triage.md`
- роадмап: `docs/specs/2026-07-12-mh-full-plan-roadmap.yml`

## Журнал волн

| Дата | Событие | Результат |
|------|---------|-----------|
| 2026-07-12 | **W0 scaffold** (анти-конфликтный каркас) на `release/mh-full-plan` | Baseline-сьют ДО каркаса: **414 passed (1451 assertions)** (`php artisan test`, БД `izigo_test_baseline`, Postgres 16 + ltree). Каркас V2 + контракты + роут-плейсхолдеры + флаги OFF. Сьют ПОСЛЕ каркаса — см. строку ниже. |
| 2026-07-12 | W0 scaffold — проверка «каркас ничего не ломает» | 414 passed (1451 assertions) — без регрессий. |
| — | W1 (T01–T04) — старт | _заполняется оркестратором_ |
| 2026-07-13 | **W2 (T05)** — лестница 12 статусов + CLIENT/grace + тиры (ветка `mh2/t05-ranks-tiers`) | 44 новых теста (22 unit ядра лестницы/тиров + 22 feature grace/персистентность/RBAC). Прогон `izigo_test_t05`: **666 passed**; 4 падения (KycTest/HealthEndpoint) — пре-существующие, воспроизводятся на чистом `release/mh-full-plan` (env APP_KEY/KYC-флаг), не связаны с T05. Флаг `mh_v2_statuses` OFF. |
| 2026-07-13 | **W? (T07)** — реферальная премия по тирам 10% L1 / 0-5-8% L2, на ОС сразу после оплаты (ветка `mh2/t07-referral-bonus`) | 22 новых теста (6 unit матрицы ставок ReferralRateResolver + 16 feature ДЕНЬГИ/идемпотентность/stop_at_elite/RBAC). Прогон `izigo_test_t07`: **692 passed**; те же 5 пре-существующих падений (KycTest/HealthEndpoint/WebAdminAuditTail-kyc) воспроизводятся на чистом base с реверсом T07 — не связаны с T07. Флаг `mh_v2_referral` OFF. Шаг `ReferralBonusStep` регистрируется в `PaidOrderV2Pipeline` (markPaid не тронут). |
| 2026-07-13 | **T10** — квалификационные награды USD (Manager..VP на БС, ручная выплата; ветка `mh2/t10-qualification-awards`) | 22 новых теста (грант/скачок DEC-040, все 10 сумм из PolicyVersion, VP этапы 2-3 DEC-042, идемпотентность, ручной payout/hold/release/forfeit, RBAC owner/finance, storno-безопасность DEC-027, гейт флага). Прогон `izigo_test_t10`: **691 passed**; те же 5 пре-существующих падений (HealthEndpoint MissingAppKey + 3×KycTest + WebAdminAuditTail-kyc), env-обусловлены, к T10 не относятся. Флаг `mh_v2_awards` OFF. Триггер наград — записи `v2_rank_history` (T05 события не эмитит) через `AwardsStep` в пайплайне пост-оплаты. |

## Baseline

- **414 тестов зелёные (1451 assertions)** на коммите основания каркаса
  (`php artisan test` в докере `izigo-php-dev`, Postgres `127.0.0.1:5544`, БД `izigo_test_baseline`).
- Любая ветка волны обязана держать сьют зелёным; новое — только добавлять.

## Зарезервированные timestamp-слоты миграций

Все миграции V2 кладутся в **общий** каталог
`mh-calc-backend-main/Modules/Calculator/Database/Migrations/` (автозагрузка
`loadMigrationsFrom` основного провайдера). Каталог `Modules/Calculator/V2/Database/Migrations/`
существует только как маркер структуры — **миграции туда НЕ класть**, отдельный
путь миграций НЕ подключён (и подключать нельзя).

Правила — как в Block C (`docs/block-c-migration-ledger.md`): каждая задача создаёт
миграции ТОЛЬКО в своём диапазоне (`xxxx` = свободный хвост внутри слота), в чужой
диапазон не прыгать, имена таблиц — по словарю схемы v2_* (amendments MF-8).

### Волна W0 (scaffold — занято)

| Кто | Слот | Файлы |
|-----|------|-------|
| W0 scaffold | `2026_07_12_09xxxx` | `2026_07_12_090000_seed_mh_plan_v2_feature_flags.php` (флаги `mh_plan_v2_engine` / `mh_plan_v2_admin` / `mh_plan_v2_miniapp`, все OFF) |

### Волна W1 (2026_07_12_*)

| Задача | Слот |
|--------|------|
| T01 — Версионируемый конфиг политики V2 | `2026_07_12_10xxxx` |
| T02 — Счета ОС/НС/БС поверх ledger | `2026_07_12_11xxxx` |
| T03 — PV/BV раздельно + PV-лоты бинара | `2026_07_12_12xxxx` |
| T04 — Расчётные периоды и джобы | `2026_07_12_13xxxx` — ЗАНЯТО (ветка mh2/t04-periods): `130000_create_v2_calc_periods_table`, `130100_create_v2_calc_runs_table`, `130200_create_v2_calc_snapshots_table`, `130300_create_v2_calc_job_executions_table`, `130400_seed_mh_plan_v2_periods_feature_flag` |

### Волны 2+ (2026_07_13_*, зарезервировано строками)

| Задача | Слот |
|--------|------|
| T05 — Лестница 12 статусов + CLIENT + тиры | `2026_07_13_10xxxx` — ЗАНЯТО (ветка mh2/t05-ranks-tiers): `100000_create_v2_partner_states_table`, `100100_create_v2_tier_history_table`, `100200_create_v2_qualification_evaluations_table`, `100300_create_v2_rank_history_table`, `100400_seed_v2_statuses_feature_flag` (флаг `mh_v2_statuses` OFF) |
| T06 — Структурная премия (бинар) с капами | `2026_07_13_11xxxx` — ЗАНЯТО (ветка mh2/t06-structural-bonus): `110000_create_v2_structure_bonuses_table` |
| T07 — Реферальная премия | `2026_07_13_12xxxx` — ЗАНЯТО (ветка mh2/t07-referral-bonus): `120000_create_v2_referral_rewards_table`, `120100_seed_mh_v2_referral_feature_flag` (флаг `mh_v2_referral` OFF) |
| T08 — Лидерский бонус | `2026_07_13_16xxxx` — ЗАНЯТО (ветка mh2/t08-leader-bonus): `160000_create_v2_leadership_bonus_lines_table`, `160100_seed_v2_leadership_feature_flag` (флаг `mh_v2_leadership` OFF). Слот 16xxxx по Волне W4 (было 13xxxx до MF/W4) |
| T09 — Глобальный пул | `2026_07_13_14xxxx` — ЗАНЯТО (ветка mh2/t09-global-bonus): `140000_create_v2_global_bonus_months`, `140010_create_v2_global_bonus_pools`, `140020_create_v2_global_bonus_qualifications`, `140030_create_v2_global_bonus_allocations` (+ partial unique unallocated), `140040_create_v2_global_bonus_payouts`, `140050_seed_feature_flag_mh_v2_global_bonus` (флаг `mh_v2_global_bonus` OFF) |
| T10 — Награды (award entitlements) | `2026_07_13_15xxxx` — ЗАНЯТО (ветка mh2/t10-qualification-awards): `150000_create_v2_award_entitlements_table`, `150100_seed_v2_awards_feature_flag` (флаг `mh_v2_awards` OFF) |
| T11 — Калибровка 60%-пула (PoolFactorService) | `2026_07_13_17xxxx` (Волна W4; T08 занял 16xxxx) |
| T12 — Возвраты/reversals | `2026_07_13_18xxxx` (сдвиг +1 из-за W4) |
| T13 — Админка V2 | `2026_07_13_19xxxx` |
| T14 — Mini App V2 | `2026_07_13_20xxxx` |
| T15 — Cutover V1→V2 | `2026_07_13_21xxxx` |
| T16 — Текст политики v3 | `2026_07_13_22xxxx` |

## Анти-конфликтный каркас (что уже существует — НЕ создавать заново)

- `Modules/Calculator/V2/` — неймспейс `Modules\Calculator\V2\*` (Domain, Services,
  Console, Http/Controllers, Contracts).
- `Modules/Calculator/V2/CalculatorV2ServiceProvider.php` — ЕДИНСТВЕННОЕ место
  DI/команд/расписания V2; в основном `CalculatorServiceProvider::register()` он
  подключён одной строкой с маркером `>>> V2` — эту строку больше не трогать.
- Контракты волны — `Modules/Calculator/V2/Contracts/` (PolicyVersionResolver, PolicyV2,
  LedgerV2, NsToOsTransfer, PvLotService, CalcPeriodService, PaidOrderV2Pipeline,
  PaidOrderV2Step). Сигнатуры зафиксированы по amendments — менять сигнатуру можно
  только правкой amendments, иначе волна разъедется.
- Роут-плейсхолдеры: `Routes/api/v2_policy.php` (T01/T13), `v2_accounts.php` (T02),
  `v2_periods.php` (T04); подключены в хвосте `Routes/api.php` блоком с маркером
  `>>> V2 routes`. Новые V2-фичи добавляют СВОЙ файл + одну require-строку в этот блок.
- Фиче-флаги (все OFF, deny-by-default): `mh_plan_v2_engine`, `mh_plan_v2_admin`,
  `mh_plan_v2_miniapp` — сид-миграция `2026_07_12_090000_*`.

## Волна W3 — ЗАКРЫТА (2026-07-12)
T06/T07/T09/T10 смержены; MF-W3-1/2 (хук наград), MF-W3-3 (реферальная вне пула), Q-W2b закрыты;
fixloop W3 acceptable. Сьют 792 passed. NOTE-ы (не блокеры): T10 stage 2/3 по порядку финализации
(денежно нейтрально); qualifiedL1Referrals фильтр по текущему state (корректно для event-time).

## Волна W4 — слоты миграций
T08 (лидерский) = 2026_07_13_16xxxx · T11 (60%-калибровка) = 2026_07_13_17xxxx

- T11 — ЗАНЯТО (ветка mh2/t11-pool-calibration): `170000_create_v2_pool_calibrations_table`,
  `170010_create_v2_pool_calibration_items_table`, `170020_seed_v2_pool_feature_flag`
  (флаг `mh_v2_pool` OFF). Контракт T11→T08/T04: factor_bps committed-строки
  v2_pool_calibrations читают PoolCalibrationReader (T08) и NsToOsTransfer (T04).

## Волна W4 — смержена, 1 CRITICAL в фиксе (2026-07-12)
T11 (60%-калибровка) + T08 (лидерский) смержены (release c3243a1, 849 passed). Интеграционное ревью:
MF-W4-1 CRITICAL (деньги) — лидерский считал от НЕкалиброванной базы (net=after_cap, factor не
применён); переплата при factor<10000. В фиксе (repro-first + интеграционный голден T11→T08).
Совет был деградирован (только Sonnet дал находки; GPT-5.1 пусто, Gemini invalid) — но оркестратор
независимо нашёл тот же баг, двойное подтверждение. Ревью-стоимость ≈$0.35.

## Волна W4 — ЗАКРЫТА (2026-07-12)
T11+T08 смержены; MF-W4-1 CRITICAL закрыт (вариант a: net_cents = after_cap×factor, единая истина,
двойного factor нет — 4 вендора подтвердили); fixloop W4 acceptable. Сьют 850 passed (перепроверен).
P2-хвост (не блокер): гарантия «нет двойного factor» держится на инварианте «структурные POSTED до
месячной калибровки» — опциональный ассерт в posting на будущее.

## Волна W5 — слоты миграций
T12 (возвраты/сторно) = 2026_07_14_10xxxx · T13 (админка V2, фронт) = без миграций ·
T14 (Mini App V2, фронт) = без миграций

## Волна W5 — ЗАКРЫТА (2026-07-12)
T12 возвраты/сторно + T13 админка V2 + T14 Mini App V2 смержены; 5 must-fix (MF-W5-1..5) закрыты,
fixloop W5 acceptable. Бэк 890 passed, фронт lint+build зелёные. Всё за флагами OFF.
Nice-to-have (не блокер, на потом): recordGlobalNote target_id ссылается на calc-period id вместо
GlobalBonusMonth.id — провенанс аудита, деньги/идемпотентность не задеты.

## Волна W6 — финальная
T15 (миграция прода + паритет V1 vs V2 + cutover) — слот миграций схемы 2026_07_14_20xxxx;
data-cutover = отдельная artisan-команда (НЕ авто на деплое). T16 (политика v3.0) — docs, зависит от T15.
Cutover-флип — на границе полумесяца (следующая: 2026-07-16 00:00 UTC).

- T15 — ЗАНЯТО (ветка `mh2/t15-migration-cutover`): `200000_create_v2_cutover_log_table`,
  `200100_create_v2_parity_runs_table`, `200200_create_v2_parity_diffs_table` (только СХЕМА,
  additive; данные прода миграцией НЕ переносятся). Инструментарий (за границей прод-исполнения):
  команды `calc-v2:cutover-migrate` (Bronze→100 + main→ОС opening, dry-run по умолчанию, `--commit`
  под ACTIVATION_LOCK, идемпотентно) и `calc-v2:parity-check` (read-only оракул V1 vs V2);
  сервисы `V2/Services/Cutover/*` (Opening/Reconciliation/Bronze/Parity); runbook
  `docs/runbooks/mh-full-plan-cutover.md` (rollback MF-10). Флаг `mh_plan_v2_engine` (сид W0)
  команды НЕ трогают — money-cutover (флип) делает координатор под owner-гейтом. Сьют **899 passed**,
  migrate:fresh OK. Решения владельца: opening-лот бессрочный (expires_at=null), PV/тиры/статусы
  с нуля (без бэкфила), Bronze 90→100 PV/100 USDT.
