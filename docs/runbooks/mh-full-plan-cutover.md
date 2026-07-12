# Runbook — cutover MH-плана V1 → V2 (T15, волна W6)

Перевод расчётного движка с V1 (`CompensationEngine` + `plan_settings`) на V2
(`Modules/Calculator/V2/*`, политика `v2_policy_versions`). Инструментарий T15 строит
**обратимую подготовку** и **необратимый флип** как ДВА раздельных шага. Этот документ —
чек-лист исполнителя (координатор/владелец), а не автомат: cutover запускается **человеком
на проде**, ничего в `deploy.yml` не меняется, автозапуска на деплое нет.

> Граница согласия (dec-triage §«Standing consent», MF-W5-5): ШАГ 1 (мердж кода за флагами
> OFF) — по standing consent. ШАГ 2 (флип `mh_plan_v2_engine` ON = money-cutover) — **красная
> зона**, требует СВЕЖЕГО явного «go» владельца в момент исполнения. Документ фиксирует
> предусловия, но НЕ заменяет это подтверждение.

## Что делает инструментарий T15

| Артефакт | Роль | Пишет деньги? |
|---|---|---|
| `calc-v2:cutover-migrate` (`--commit`) | Bronze→100 PV/100 USDT + перенос main-баланса → бессрочный opening-лот ОС | да (только под `--commit`, под ACTIVATION_LOCK) |
| `calc-v2:parity-check` | read-only оракул V1 vs V2 (таблица match/mismatch/plan_change/v2_only) | нет |
| `LedgerReconciliationService` | trial balance + кэши == свёртка ledger + лоты == кэш | нет |
| фиче-флаг `mh_plan_v2_engine` | точка переключения V1↔V2 (уже вшита в `OrderService`/`PaymentService`) | — |

**Границы решений владельца (dec-triage 2026-07-12):**
- Денежный main-баланс → ОС **бессрочным** opening-лотом (`expires_at = null`).
- PV / тиры / статусы — **с нуля**, без бэкфила (стартуют по факту оплат в V2).
- Bronze поднимается 90→**100 PV / 100 USDT** на cutover (каждый покупатель ≥ порога START).
- Cutover строго на **границе полумесяца** (1-е / 16-е 00:00 UTC), чтобы период не считался
  смешанно V1/V2.

`held`/`clawback` НЕ переносятся: открытые заявки на вывод доживают по V1-пути.

---

## Предусловия (перед флипом — все обязательны)

1. **Код V2 в проде за флагами OFF** (ШАГ 1 выполнен: `release/mh-full-plan` → `main`,
   `mh_plan_v2_engine` = OFF; прод-поведение не изменилось — боевой движок всё ещё V1).
2. **Свежий бэкап Postgres** (PITR активен + ночной logical-дамп; см. корневой CLAUDE.md
   «Бэкапы Postgres»). Проверить дату последнего дампа.
3. **Мониторинг живой** (Azure Monitor `al-ca-izigo-*` + server-watchdog + `/api/health`).
4. **Сверка ledger green:**
   ```
   php artisan tinker --execute="dump(app(\Modules\Calculator\V2\Services\Cutover\LedgerReconciliationService::class)->check()['ok']);"
   ```
   Ожидание `true`. Любой дрейф (trial balance ≠ 0, кэш ≠ свёртка, лоты ≠ кэш) → **СТОП**,
   разобрать до cutover.
5. **Паритет принят:** `calc-v2:parity-check` даёт `unexplained_delta = 0`, `conservation_ok`,
   ни одного `mismatch`; отчёт предъявлен владельцу «одним взглядом», получен owner-accept.
   `plan_change` / `v2_only` (новые механики V2) — не блокеры by-design.
6. **Нет заявок на вывод в processing** (held в работе): дождаться завершения/паузы, иначе
   деньги «в пути» усложнят сверку. **Энфорс:** `parity-check` показывает held/clawback тоталы,
   а `cutover-migrate --commit` **аборт-ит**, если у кого-то `held_cents > 0` (разрулить выводы
   до cutover — одобрить+выплатить или отклонить).
7. **Дата = граница полумесяца** (следующая: 2026-07-16 00:00 UTC).
8. **Rollback-плейбук (ниже) отрепетирован на staging-копии прод-данных** (MF-10 —
   обязательно до PROD-гейта).

---

## Ход cutover

### Шаг A — паритет (обратимо)
```
php artisan calc-v2:parity-check
```
Прочитать таблицу per-member (match/mismatch/plan_change/v2_only) и сводку. `mismatch` = стоп.

### Шаг B — dry-run переноса (обратимо, без записи)
```
php artisan calc-v2:cutover-migrate --actor="<owner>"
```
Показывает план Bronze→100 и переноса main→ОС по каждому партнёру + предварительную сверку.
Ничего не пишет в ledger. Построчные суммы (member_id/available_cents) печатаются **только в
dry-run**; при `--commit` в stdout идёт лишь агрегат (детали — в `v2_cutover_log`).

### Шаг C — commit переноса (пишет деньги; ОБРАТИМО через rollback ниже)
```
php artisan calc-v2:cutover-migrate --commit --actor="<owner>"
```
Под ACTIVATION_LOCK в одной транзакции: Bronze→100, reclass `Dr member_available /
Cr member_os_available` + бессрочный opening-лот ОС по каждому партнёру, запись в
`v2_cutover_log`. Идемпотентно (повтор ничего не задваивает). Прекондишен-сверка не сошлась
→ команда падает БЕЗ единой проводки. По завершении печатает пост-сверку (ожидание OK).

> Флаг движка команда НЕ трогает — перенос баланса и флип разделены намеренно.

### Шаг D — money-cutover: флип движка (НЕОБРАТИМЫЙ рубеж — только по свежему owner-«go»)
Строго на границе полумесяца:
```
php artisan tinker --execute="app(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)->set('mh_plan_v2_engine', true);"
```
С этого момента `OrderService::markPaid` / `PaymentService` считают по V2-пайплайну; V1
`recompute()` больше не вызывается (снапшот `member_earnings`/`member_bonus_lines` заморожен,
не стирается). При желании включить V2-разделы UI: `mh_plan_v2_admin`, `mh_plan_v2_miniapp`.

---

## Проверки после флипа

- `curl -fsS https://<backend>/api/health` → 200 (БД + heartbeat планировщика).
- Тестовая оплата (или наблюдение первой боевой) создаёт V2-начисления (реферальная на ОС
  сразу; PV-лоты; статусные события) — смотреть `v2_*` таблицы, не `member_earnings`.
- `LedgerReconciliationService->check()['ok']` == `true` (ежедневно первые дни).
- Первое закрытие half-month/month отрабатывает штатно (`calc-v2:*` по расписанию, гейт
  `mh_plan_v2_periods`).

---

## Rollback (MF-10)

Критерий отката: расхождение паритета всплыло после флипа, ошибка расчёта V2, инцидент денег.
**Решение об откате — владелец.**

### Сценарий 1 — окно «после флипа не оплачен ни один заказ» (простой откат)
V2 ещё не начислил денег → достаточно выключить флаг:
```
php artisan tinker --execute="app(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)->set('mh_plan_v2_engine', false);"
```
V1 немедленно возобновляется. При необходимости вернуть opening-деньги в V1 main-баланс —
компенсирующий reclass ОС→`member_available` под ACTIVATION_LOCK (ключи `v2rollback:m{id}`,
зеркало opening-переноса; V2-проводки НЕ удаляются, append-only). После — сверка `ok`.

### Сценарий 2 — после флипа уже прошли V2-оплаты (компенсирующий откат)
Простой флип OFF НЕ достаточен (V2 успел начислить). Порядок (всё под ACTIVATION_LOCK,
на staging-копии отрепетировать заранее):
1. Выключить `mh_plan_v2_engine` (остановить новые V2-начисления).
2. **Компенсирующие V2-reversals** по всем заказам окна после флипа (движок возвратов T12:
   `BonusReversalService` / `PvLotReversalService` — сторнируют реферальную/структурную/лоты).
3. **V1-backfill**: пере-провести те же заказы окна через V1 (`ActivationService`/`recompute`),
   чтобы партнёры получили V1-начисления за период.
4. Компенсирующий reclass opening ОС→`member_available` (если решено вернуть базу в V1).
5. Сверка `LedgerReconciliationService->check()['ok']` == `true`; повторный откат — no-op.

> Разные namespace ключей идемпотентности (`v2migrate:` / `v2rollback:` / `accrual:` V1)
> гарантируют, что последующий пересчёт V1 не задваивает начисления.

### Возврат старого (pre-cutover) заказа после флипа
V1-начисление такого заказа уже вошло в opening-баланс ОС. Ручной возврат идёт **корректирующей
проводкой по ОС** (правило T12), а не сторно несуществующих V2-строк.

---

## Команды проверки живости

```
# health бэка
curl -fsS https://ca-izigo-backend.<...>.azurecontainerapps.io/api/health

# состояние флагов
php artisan tinker --execute="dump(app(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)->all());"

# сверка ledger
php artisan tinker --execute="dump(app(\Modules\Calculator\V2\Services\Cutover\LedgerReconciliationService::class)->check());"

# журнал cutover
php artisan tinker --execute="dump(\Modules\Calculator\Models\V2\CutoverLog::query()->latest('id')->limit(20)->get()->toArray());"
```

Прод-write (флип/reclass) — только из авторизованной сессии владельца (`az`/exec в ACA); MSI
mh-central на RG `rg-izigo-beta-neu` имеет только read-роли.

---

## Связанные документы
- Планы T15: `docs/specs/2026-07-12-mh-full-plan-gateA-plans.md` (раздел T15)
- Поправки: `docs/specs/2026-07-12-mh-full-plan-gateA-amendments.md` (MF-10)
- Решения владельца: `docs/specs/2026-07-12-mh-full-plan-dec-triage.md` (миграция «с нуля»,
  Bronze→100, standing consent PROD-гейта)
- Ledger-журнал волн: `docs/mh-full-plan-migration-ledger.md` (W6)
