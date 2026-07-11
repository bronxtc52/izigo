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
| T05 — Лестница 12 статусов + CLIENT + тиры | `2026_07_13_10xxxx` |
| T06 — Структурная премия (бинар) с капами | `2026_07_13_11xxxx` |
| T07 — Реферальная премия | `2026_07_13_12xxxx` |
| T08 — Лидерский бонус | `2026_07_13_13xxxx` |
| T09 — Глобальный пул | `2026_07_13_14xxxx` |
| T10 — Награды (award entitlements) | `2026_07_13_15xxxx` |
| T11 — Калибровка 60%-пула (PoolFactorService) | `2026_07_13_16xxxx` |
| T12 — Возвраты/reversals | `2026_07_13_17xxxx` |
| T13 — Админка V2 | `2026_07_13_18xxxx` |
| T14 — Mini App V2 | `2026_07_13_19xxxx` |
| T15 — Cutover V1→V2 | `2026_07_13_20xxxx` |
| T16 — Текст политики v3 | `2026_07_13_21xxxx` |

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
