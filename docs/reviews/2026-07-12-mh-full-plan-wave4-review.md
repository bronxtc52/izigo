# Интеграционное ревью — mh-full-plan Волна W4 (T11 60%-калибровка + T08 лидерский)

**Дата:** 2026-07-12 · **Режим:** review-diff (совет моделей + оркестратор-судья)
**Диапазон:** `0566f52..c3243a1` (merge train W4: T11 → T08), 38 файлов, +3553
**Контекст-контракт:** `docs/specs/2026-07-12-mh-full-plan-gateA-amendments.md`
**Вердикт:** 🔴 **request_changes** — 1 подтверждённый CRITICAL (деньги), стоп до фикса.

---

## Вердикт одной строкой

Формула 60%-пула, целочисленная математика, RBAC, идемпотентность, порядок DEC-053 и
rank-gap лидерского — **корректны**. Но **стык T11→T08 разорван**: лидерский бонус
считается от **некалиброванной** базы (after_cap), а не от post-calibration net (DEC-029).
При factor_bps < 10000 (штатный режим переподписки пула) лидерский **систематически
переплачивается**. Это блокер.

## Совет моделей (честно: DEGRADED COUNCIL)

- Findings дал 1 внешний ревьюер — **Claude Sonnet 4.5** (correctness, 10 находок).
- **GPT-5.1** (correctness + security) вернул пустой `content` — reasoning-модель сожгла
  бюджет на reasoning-токены (известная грабля), находок не выдал.
- **Gemini 2.5 Pro** (security) не отдал валидный JSON, fallback ушёл в пустой GPT-5.1.
- Кворум < 3 → **отчёт помечен DEGRADED**. Компенсация: независимое углублённое ручное
  ревью оркестратора (Opus) прошло **до** совета и нашло тот же CRITICAL — **двойное
  независимое подтверждение** ключевого дефекта с evidence `file:line` и отсутствующим
  интеграционным тестом.

---

## MUST-FIX (блокеры)

### MF-W4-1 [CRITICAL, деньги] Лидерский бонус считается от НЕкалиброванной базы (after_cap), а не post-calibration net — стык T11→T08 не реализован

**Одной строкой:** `LeadershipBonusService` берёт `v2_structure_bonuses.net_cents`, которое
навсегда остаётся `= after_cap_cents` (T11 его не пере-пишет, T08 factor_bps не применяет) →
лидерский переплачивается на `(1 − factor_bps/10000)` с каждого источника (при factor 6000 —
на ~67%), реальными деньгами на ОС (кредит-лот 1 год).

**Доказательство (цепочка, все в этой волне/рядом):**
- `V2/Services/Bonus/StructureBonusService.php:166` — `'net_cents' => $calc->afterCapCents`
  (raw, до пула). Коммент прямо: «= after_cap до 60%-пула T11».
- `V2/Services/Pool/PoolCalibrationService.php:134-147` — для структурной пишет **только**
  проекции `PoolCalibrationItem(state=projected)`; `v2_structure_bonuses.net_cents`
  **не обновляет**. Обновляет лишь `final_cents` глобальных аллокаций (строка 155).
- `grep` по всему `Modules/Calculator/V2` (не-тест): **ни одного** write в
  `v2_structure_bonuses.net_cents` после T06. (`pool_adjustment_cents`/`pool_coefficient`
  тоже никто не заполняет.)
- `V2/Services/Bonus/StructureBonusBaseSource.php:35-39` + `LeadershipBonusService.php:91` —
  читают `net_cents` как есть, `PoolCalibrationReader`/`factor_bps` не инжектят и не применяют.
- Все док-комментарии (`StructureBonus.php:10`, `StructureBonusPostingService.php:19-20`,
  `LeadershipCloseStep.php:14`, миграция `..._160000...:14-15`) **утверждают** «T11 пишет
  калиброванный net в v2_structure_bonuses ДО шага лидерского» — **код этого не делает**.

**Почему тесты зелёные (маскировка):** `LeadershipBonusServiceTest::test_base_is_net_cents_after_calibration_not_after_cap`
вручную сидит `net_cents=500_000` (коммент «эмуляция 60%-калибровки T11»). Интеграционного
теста, который прогонит **T11 калибровку → затем T08** на одних и тех же строках, **нет**.
`PoolCalibrationCloseTest` проверяет только структурный НС→ОС (он-то калиброван верно), но
лидерский шаг в нём не гоняется.

**Важно:** структурный payout партнёру — **корректен** (НС→ОС в T02/T04 множит на factor_bps,
`PoolCalibrationCloseTest` это доказывает: 10000→OS 6000, retained 4000, двойная запись
сходится). Дефект изолирован в **базе лидерского**.

**Фикс (один из):**
1. **T11 пишет калиброванный net** в `v2_structure_bonuses` для каждого участника ДО шага
   лидерского (тогда все док-комментарии станут правдой), **либо**
2. **T08 применяет factor** — инжектить `PoolCalibrationReader`, `base = intdiv(net_cents ×
   factorBpsFor(month), 10000)` (по-участниково-floor, чтобы совпадать с НС→ОС T02).
   Fail-closed: нет committed-калибровки → лидерский не считать.

**Обязательно:** добавить **интеграционный** голден: month-close с factor<10000, затем
лидерский; assert, что база = calibrated, а не after_cap.

---

## Проверено и КОРРЕКТНО (по фокусу задачи)

**(1) Формула 60%-пула / целочисленность** (`PoolFactor.php`, `PoolCalibrationService.php`):
- `pool_cap = intdiv(base_bv×rate,10000)`; `factor = total==0?10000:min(10000,intdiv(cap×10000,total))`
  — только scale-down, `factor∈[0,10000]`, не «всегда 1» (bps-шкала). ✔
- `Σ paid ≤ pool_cap`: доказуемо. Структурная — per-member floor (`Σ⌊xᵢ⌋ ≤ ⌊Σxᵢ⌋`),
  глобальная — largest-remainder (`Σ = ⌊total×f/10000⌋`); сумма ≤ `⌊total×f/10000⌋ ≤ cap`. ✔
- `distribute()` largest-remainder, tie-break по ключу ASC (детерминизм); остаток раздаётся
  только строкам с ненулевым остатком → `paid ≤ raw` держится (проверено алгебраически;
  Sonnet-находка «может overpay item» — **false positive**, инвариант выполняется). ✔
- **Числитель** `total_after_caps = structure_after_caps + global_capped` — реферальная,
  лидерский, награды исключены (`PolicyConfigValidator` жёстко валит
  `include.leadership≠false` и `include.awards≠false`; реферальная — отдельной строкой без
  factor). Точно по MF-1/MF-W3-3. ✔
- **Дельта в sink**: `company_retained = total − scaled`; НС→ОС постит `company_pool_retained`
  двойной записью (подтверждено `PoolCalibrationCloseTest`: retained=4000, debit==credit). ✔

**(2) Лидерский:** «блок без передачи» (DEC-030) — `belowMaxOrdinal ≥ rankOrd+gap`, verdict
per-receiver, выше по цепочке walk продолжается (депт инкрементится без компрессии), выплата
не роллапится. Пример Директора (Diamond ord10 ≥ 7+3 блок; Sapphire ord9 < 10 платит) —
тесты зелёные. Ставки 20/10/5/3/1/1/1 по глубине, глубина ELITE = `min(global, rank)` —
`resolveRate` режет за пределом ранга (DEPTH_NOT_ALLOWED). Sonnet-находки #4 (инверсия gap) и
#6 (депт за рангом) — **false positives** (тесты + код опровергают). ✔

**(3) Порядок DEC-053:** `PeriodCloseStepRegistry:42` сортирует **по возрастанию**
(`order() <=> order()`): global-allocate(300) → **pool(500) → leadership(800)** → global-finalize(900).
Порядок верный. Sonnet-находка #2 («leadership 800 раньше pool 500») — **false positive**
(перепутано asc/desc). Оговорка: правильный порядок **не спасает** от MF-W4-1, т.к. T11 всё
равно не пишет net. ✔ (порядок) / см. MF-W4-1 (данные)

**(4) T08 читает закоммиченный factor:** `PoolCalibrationReadService` отдаёт factor_bps только
committed-строки, fail-closed при null. Контракт reader — на месте (но T08 его **не
использует** — это и есть MF-W4-1). ✔ (контракт) / ✗ (потребление)

**(5) Идемпотентность / RBAC:**
- Лидерский: ключ `(source_structure_bonus_id, receiver_member_id)`, POSTED→skip, ledger
  идемпотентен по `v2:leadership:{sb}:{recv}`; повтор — no-op (тест зелёный). ✔
- Пул: supersede прежней committed + `run_version+1`, partial-unique `WHERE status='committed'`
  гарантирует одну committed/месяц; всё под ACTIVATION_LOCK. ✔
- RBAC: `v2_pool.php`/`v2_leadership.php` — deny-by-default `feature.flag:*`; mutation
  (recalibrate) — `calculator.role:owner`; read — `owner,finance`; cabinet — `telegram.auth`,
  IDOR-safe (receiver из auth, chosen id игнорируется); recalibrate на CLOSED → 422; аудит
  `recordSafe`. Negative-тесты присутствуют. ✔

---

## Non-blocking (P2, на усмотрение — хардненинг)

- `LeadershipCalculator::compute` не валидирует `baseCents ≥ 0` (в проде отфильтровано
  `net_cents>0`, но defense-in-depth уместен). (Sonnet #10)
- `persistAndPost` может перезаписать `excluded`→`accrued` при повторе, если данные изменились;
  для закрытого периода снапшоты as-of детерминированы, риск ~0. (Sonnet #8)
- `structureAfterCapsByMember` читает `v2_structure_bonuses` без row-lock; защищено
  ACTIVATION_LOCK + закрытыми half-month, но явный комментарий об инварианте не помешает. (Sonnet #9)
- Флаги `mh_v2_pool` / `mh_v2_leadership` засеяны **OFF** (deny-by-default) — подтверждено
  merge-репортом (migrate:fresh: оба f). ✔

## Стоимость ревью-гейта

Совет моделей через OpenRouter (standing consent, платный): фактический usage раннером не
логируется. **Оценка ≈ $0.30–0.40** (GPT-5.1 ×2 reasoning-прогона впустую, Gemini 2.5 Pro
partial, Sonnet 4.5 — единственный продуктивный, ~13K in / ~4K out). Записать в леджер сессии.
