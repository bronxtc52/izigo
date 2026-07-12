# Ревью советом моделей — T15 (mh-full-plan, W6): инструментарий миграции прода + паритет + cutover

- **Дата:** 2026-07-12
- **Режим:** review-diff (multi-llm-reviewer, preset `strong`)
- **Дифф:** `git diff 222e6bd..251bfc5` (merge-коммит W6 T15), 19 файлов, 1678 вставок, packet 93KB
- **Ветка:** `release/mh-full-plan`
- **Контекст:** ПОСЛЕДНИЙ код перед прод-деплоем, денежно-миграционный (реальные балансы 5 участников)

## Вердикт: **request_changes**

Денежное ядро миграции корректно и покрыто тестами (двойная запись, идемпотентность,
abort-on-drift, dry-run по умолчанию, manual-only). Блокирующих денежных потерь на happy-path
не найдено. Но **оракул owner-гейта (`ParityCheckService`) имеет две реальные дыры**, которые
надо закрыть до того, как отчёт паритета можно доверять как критерию go/no-go денежного cutover.
Не `block` (ядро денег верное и протестировано; денежный путь оракула работает), но и не
`approve` (инструмент гейта неполон).

## Совет моделей

| Роль | Модель (ответила) | Вендор | Находок по T15 |
|---|---|---|---|
| architect | anthropic/claude-opus-4.8 | Anthropic | 3 (1 high) |
| security | openai/gpt-5.5-pro | OpenAI | 1 (medium) |
| product_risk | anthropic/claude-opus-4.8 | Anthropic | 2 (1 high) |
| doc | deepseek/deepseek-v4-pro | DeepSeek | 7 (3 «high» — все ложные, см. ниже) |
| performance | anthropic/claude-sonnet-5 | Anthropic | 0 |
| correctness | anthropic/claude-opus-4.8 | Anthropic | 5 — **все вне scope T15** |
| tests | deepseek/deepseek-v4-pro | DeepSeek | 9 — **все вне scope T15** |
| maintainability | deepseek/deepseek-v4-flash | DeepSeek | 5 — **все вне scope T15** |

**Кворум:** 8 ролей ответили. **3 разных вендора с валидными ответами** (Anthropic, OpenAI,
DeepSeek) — требование ≥3 выполнено, НЕ деградировано. Google/Gemini не дал валидного ответа
(non-JSON ×3 на architect/maintainability) — в консенсус не вошёл.

**Оговорка о качестве совета:** 3 роли (correctness/opus, tests/deepseek, maintainability/deepseek)
ушли в context-bleed — рецензировали файлы, которых НЕТ в диффе T15 (`WalletAccountsV2Service`,
`BonusReversalService`, `RefundService`, фронтовые refunds/periods) — это уже-смердженный код
T04/T09/T12, зацепленный по import-именам. Эти находки отброшены как вне-scope/непроверяемые из
packet. Глубину по T15-специфичной логике компенсировал ручной проверкой (тесты, start.sh,
`isAcceptable`, runbook, reconciliation) — см. ниже.

## Must-fix (блокируют approve)

1. **Оракул паритета пропускает mismatch дерева мимо гейта.** `ParityRun::isAcceptable()` =
   `status==done && unexplained_delta_cents==0`, но `tree_composition` mismatch добавляет
   `$unexplained += 0` (явный ноль). Итог: рассинхрон генеалогии (узла нет в сети V1-движка ИЛИ
   `members.sponsor_id` разошёлся с деревом) классифицируется MISMATCH и печатается в таблице как
   MISMATCH, но `unexplained_delta` остаётся 0 → `isAcceptable()==true` → команда пишет «Отчёт
   ПРИЕМЛЕМ» и выходит SUCCESS. Прямо противоречит фокусу №4 («tree_composition = MUST-MATCH»,
   «критерий = unexplained==0 И conservation_ok»). Фикс: гейт должен требовать ноль строк mismatch
   (напр. `byClass[MISMATCH]==0`), а не только `unexplained==0`. Подтверждено architect(opus) +
   ручной верификацией. `ParityCheckService.php:163-172`, `ParityRun.php::isAcceptable`.
   *Repro-first:* ни один T15-тест не форсит tree-mismatch — именно поэтому дыра проскочила;
   добавить красный тест вместе с фиксом.

2. **held/clawback невидимы в отчёте, который подписывает владелец.** Оракул читает только
   `available_cents`; `held_cents`/`clawback_debt_cents` не читаются нигде, а `conservation_ok`
   считается true. Партнёр с деньгами в held (заявка на вывод в полёте) на момент cutover не
   всплывает на гейте go/no-go — деньги тихо расщепляются V1/V2. Миграция ПРАВИЛЬНО не переносит
   held (решение владельца, V1-путь), но это должно быть ЯВНО видно на гейте. Фикс: вывести
   held+clawback тоталы в отчёт паритета и/или abort `cutover-migrate --commit`, если у кого-то
   `held_cents>0`. Подтверждено product_risk(opus). `ParityCheckService.php:119-131`.

## Should-fix (не блокируют, заметки)

3. **Финансовые данные на member по строкам в stdout команды** — `cutover-migrate` печатает
   таблицу `member_id / available_cents` в консоль; при запуске через ACA exec это попадёт в
   console-логи. Не PII/секрет (TON-адресов и секретов нет), но лишняя денежная детализация.
   Спрятать за `--verbose` или маскировать суммы. security(gpt-5.5-pro), `CutoverMigrateCommand.php:64-66`.
   *Фокус №7 по сути выполнен:* `v2_cutover_log` хранит только member_id/amount/tx_id/detail —
   ни TON-адресов, ни секретов.

4. **Rollback Сценарий 2 (MF-10) — ручная многошаговая компенсация, не скриптованная команда.**
   Опирается на существующие сервисы T12 (`BonusReversalService`/`PvLotReversalService` —
   существуют) + `ActivationService`/recompute + reclass. Приемлемо для редкого отката по MF-10,
   но runbook сам требует «отрепетировать на staging» — убедиться, что staging-прогон сделан до
   того, как на это положатся; добавить явную строку захвата ACTIVATION_LOCK в ручные шаги.

5. **Мелочь:** source_type проводки `'v2_opening'` vs source_type лота `'opening_migration'` —
   два имени одной провенанс-сущности; выровнять или задокументировать. architect(opus), низкий.

## Отброшено как невалидное / вне scope T15

- **doc(deepseek) 3× «BLOCKING» — ложные срабатывания.** «Несуществующая команда
  `calc-v2:rollback-migrate`» — runbook такой команды НЕ упоминает (rollback идёт через
  `FeatureFlagService->set(...)` в tinker + компенсирующий reclass). «Несуществующие
  `BonusReversalService`/`PvLotReversalService`» — оба СУЩЕСТВУЮТ
  (`V2/Services/Refunds/`, T12). Причина: deepseek видел только дифф-packet, не репозиторий, и
  при невозможности проверить существование дефолтил в «не существует». Проверено вручную.
- **correctness(opus) / tests(deepseek) / maintainability(deepseek)** — находки о
  `WalletAccountsV2Service` (NS-reversal), `BonusReversalService`, `RefundService`, фронтовых
  refunds/periods/policy: эти файлы НЕ в диффе T15 (уже-смерженный код), из packet непроверяемы,
  context-bleed. Не относятся к T15.

## Проверка фокус-областей (ручная верификация судьи)

| # | Фокус | Итог |
|---|---|---|
| 1 | Сохранение денег (двойная запись, Σ, held/clawback явно) | **PASS** — reclass Dr member_available / Cr os_available сбалансирован, Σ сохраняется; held/clawback НЕ переносятся by design (задокументировано). Замечание: не видны в оракуле (must-fix #2). |
| 2 | Идемпотентность `--commit`, lock, abort-on-drift, транзакционность | **PASS** — тесты `test_commit_is_idempotent`, `test_commit_aborts_on_reconciliation_drift`; всё в одной `DB::transaction` под `acquireActivationLock()`; идемпотентность по `alreadyPosted(key)` + unique lot key. |
| 3 | dry-run по умолчанию, ничего не мутирует на деплое | **PASS** — `--commit` обязателен; `cutover-migrate` НЕ в `docker/start.sh` и НЕ в workflows (manual-only). `test_dry_run_prints_plan_without_writes`. |
| 4 | Оракул паритета корректен, exit-код | **PARTIAL / FAIL** — money-путь верен и гейтится; tree_composition MUST-MATCH НЕ энфорсится в гейте (must-fix #1); held не показан (must-fix #2). |
| 5 | Bronze→100 (только будущие покупки, история не тронута, PV=1 USD) | **PASS** — правит только строку `Product` (TARGET_PV=100, 10000 центов), исторические заказы не трогает, BV держит = цене, идемпотентно. |
| 6 | Rollback MF-10 (flip OFF / компенсирующие reversals + V1-backfill под локом) | **PASS с заметкой** — оба сценария покрыты и реалистичны; Сценарий 2 ручной (see should-fix #4). Утверждения doc-рецензента о «несуществующих» сервисах — ложны. |
| 7 | Секьюрити: нет PII/секретов в cutover-log | **PASS** — лог без TON-адресов/секретов. Консольный вывод балансов — see should-fix #3. |

## Тест-покрытие T15 (проверено вручную, т.к. tests-рецензент ушёл вне scope)

Покрыто: dry-run-без-записи, commit(opening+bronze), идемпотентность commit, abort-on-drift,
паритет на 5 участниках, паритет флагует cache-drift как mismatch, ledger reconcile ok / detect
cache drift / reconcile after opening migration. **Пробел:** нет теста на tree_composition
mismatch (маскирует must-fix #1) и на held/clawback в оракуле (must-fix #2).

## Стоимость

**Оценочно ~$2.0–2.5** (точный per-call раннером не захвачен). Доминанта: 3× Opus 4.8
(architect/correctness/product_risk) + 3× попытки GPT-5.5-pro (security успех, correctness ×2
пустой ответ — input оплачен), + Sonnet-5, DeepSeek v4 pro/flash (центы), + 3 неоплачиваемо-мелких
провала Gemini (non-JSON). Packet ~26K input-токенов × ~13 инвокаций. Платный совет, standing
consent — карточка не показывалась.

**Деградация: НЕТ** по требованию ≥3 вендоров (Anthropic/OpenAI/DeepSeek дали валид). Оговорка:
Google не дал валидного ответа, и 3 роли ушли в context-bleed — компенсировано ручной проверкой
T15-ядра.

---

# Fixloop T15 (verify-pass советом моделей, 2026-07-12)

- **Режим:** review-fixloop (multi-llm-reviewer). Re-review закрытия находок MF-1/MF-2/#3 +
  ProductSeeder-durability + КРИТИЧЕСКАЯ проверка FeatureFlagSeeder-на-рестарте.
- **Дифф:** `251bfc5^..d6e04f6` (3 фикс-коммита), packet ~112KB.
- **ПОСЛЕДНИЙ гейт перед прод-деплоем денежной миграции.**

## Вердикт: **acceptable** (правки закрывают находки; блокеров нет)

Все пять пунктов закрыты и покрыты зелёными red-тестами (14 T15-тестов / 99 assertions PASS:
`CutoverMigrateTest`, `ParityCheckTest`, `LedgerReconciliationTest`). КРИТИЧЕСКИЙ риск
FeatureFlagSeeder-на-рестарте — **подтверждённо отсутствует** (не блокер). Совет выдал набор
hardening-находок, ни одна не является денежной потерей или реальной дырой go/no-go при
проверке против фактического кода — детально ниже.

## Совет моделей (≥3 разных вендора — выполнено, НЕ деградировано)

| Роль | Модель (ответила) | Вендор | Статус |
|---|---|---|---|
| product_risk | anthropic/claude-opus-4.8 | Anthropic | ok (5 находок) |
| architect | anthropic/claude-opus-4.8 | Anthropic | ok (3 находки) |
| correctness | deepseek/deepseek-v4-pro | DeepSeek | ok (3 находки) |
| security | openai/gpt-5.5 (fallback с -pro) | OpenAI | ok (2 находки) |
| correctness | openai/gpt-5.5 (fallback с -pro) | OpenAI | ok (3 находки) |
| architect | google/gemini-3.1-pro-preview | Google | **failed** (пустой контент ×2) |

**Кворум: 3 разных вендора с валидными ответами (Anthropic / DeepSeek / OpenAI).** Google не
дал валидного ответа (как и в исходном ревью). DeepSeek получил обезличенный packet (or-sanitize,
КНР-правило); прочим — чистый (secret-scan packet'а CLEAN: только синтетические member_id 9001–9005,
без TON-адресов/секретов/PII).

## Проверка закрытия

**MF-1 — гейт паритета отклоняет ЛЮБОЙ mismatch. ЗАКРЫТО.**
`ParityRun::isAcceptable()` теперь = `status==done` И `unexplained==0` И
`by_classification[MISMATCH]==0` И `conservation_ok`. Каждая проверочная строка (money/held/
clawback/tree/accrued) инкрементит `$byClass[$class]` — **нет пути, где mismatch не
классифицируется и проскакивает**. Red-тесты доказывают: tree-mismatch при НУЛЕВОЙ денежной
дельте → `isAcceptable()==false` и `parity-check` exit 1. Exit-коды команды согласованы
(`SUCCESS`/`FAILURE` по `isAcceptable`).

**MF-2 — held/clawback видны + commit аборт на held>0. ЗАКРЫТО (общий случай).**
Оракул выводит `held_total_cents`/`clawback_total_cents`/`members_with_held` + построчные
CHECK_HELD/CHECK_CLAWBACK; дрейф кэша held/clawback vs ledger добавляется в `$unexplained` →
блокирует accept. `cutover-migrate --commit` абортит на `held_cents>0` (red-тест
`test_commit_aborts_when_member_has_held_balance`). clawback показан, но НЕ блокирует commit —
by-design (подтверждено product_risk).

**should-fix #3 — PII в stdout. ЗАКРЫТО для --commit.** Построчные суммы печатаются только в
dry-run; при `--commit` stdout — агрегат, детали в `v2_cutover_log` (member_id/amount/tx_id,
без TON/секретов).

**ProductSeeder cutover-durability. ЗАКРЫТО.** `firstOrNew` + create-only для `pv`/`price`/
`is_active` существующего тарифа; косметику (name/desc/sort/package_id) держит свежей; отсутствующий
тариф создаёт с дефолтами. Red-тест `test_product_seeder_does_not_revert_bronze_after_cutover`:
Bronze остаётся 100 после ресида, SILVER пересоздаётся при удалении.

**КРИТИЧЕСКОЕ — FeatureFlagSeeder на рестарте. VERIFIED CLOSED (НЕ блокер).**
`mh_plan_v2_engine` **вообще отсутствует** в списке FeatureFlagSeeder (тот сеет только
c1–c7 + ai_assistant, через `firstOrCreate` — не перетирает `enabled`). Флаг движка создаётся
**миграцией** `2026_07_12_090000_seed_mh_plan_v2_feature_flags.php` (`insertOrIgnore`), а миграции
трекаются и **не перезапускаются** (`migrate --force` не гоняет уже применённые). Ни один сидер из
start.sh (ProductSeeder/FeatureFlagSeeder/AgreementSeeder) не трогает `mh_plan_v2_engine`.
**Рестарт после cutover НЕ вернёт прод на V1.** (Подтверждено product_risk/Anthropic + ручной
трассировкой.) AgreementSeeder тоже безопасен (`if PlanSetting::get('agreement')!==null return`).

## Находки совета (hardening; ни одна не блокер — проверено против кода)

1. **[3-вендорный консенсус: DeepSeek + OpenAI×2] TOCTOU: held/reconciliation пре-чек ВНЕ
   транзакции.** Пре-чек `held_cents>0` (и сверка ledger) читается ДО `DB::transaction` +
   `acquireActivationLock()`. Окно: вывод, созданный между пре-чеком и стартом транзакции, не
   тригерит «аборт на любой held» → баланс партнёра расщепится V1-held / V2-OS.
   **НЕ денежная потеря/задвоение:** `migrateMember` перечитывает живой `available_cents` под
   `lockForUpdate()` и двигает 1:1 строгой двойной записью; `WithdrawalService` row-локает тот же
   wallet (`DB::transaction`+`lockForUpdate`) → операции сериализуются; Σ сохраняется, trial
   balance 0; пост-сверка детектит. Остаётся только неатомарность НАМЕРЕНИЯ аборта. DeepSeek
   ошибочно оценил как «double-count/unrecoverable» — против кода это неверно; OpenAI корректно
   MEDIUM. **Рекоменд. (дёшево, до флипа):** повторить held-чек ВНУТРИ транзакции после
   `acquireActivationLock` и аборт до записей; ИЛИ строкой в runbook заморозить одобрение выводов
   на окно cutover (операция ручная, одним оператором, в maintenance-окне — риск операционно мал).

2. **[2-вендорный консенсус: Anthropic architect + OpenAI correctness] `isAcceptable()` читает
   mismatch из денормализованного `summary`, а не из строк `v2_parity_diffs`.** СЕЙЧАС дыры нет:
   `run()` пишет diffs и `summary` атомарно в одном методе; нет пути, пишущего diffs без summary;
   `?? false` на conservation → null-summary фейлится-закрыто. Но для денежного гейта надёжнее
   выводить гейт из источника истины: `if ($this->diffs()->where('classification','mismatch')
   ->exists()) return false;`. Опциональный hardening, не блокер.

3. **[OpenAI correctness, HIGH — но misread by-design] «held/clawback не в conservation-тоталах».**
   `v1Total`/`v2Total` намеренно только available — held/clawback НЕ мигрируют (V1-путь), их не
   должно быть в тотале переносимой суммы. Целостность held/clawback обеспечена ОТДЕЛЬНО: их дрейф
   кэш-vs-ledger идёт в `$unexplained` → блокирует accept; тоталы явно в summary. MF-2 «видно на
   гейте» + «дрейф блокирует» выполнены. Не дыра; максимум — добавить в summary справочную строку
   «полный баланс» для наглядности владельцу.

4. **[DeepSeek, MEDIUM] ProductSeeder всегда перезаписывает `package_id` существующего тарифа.**
   `package_id` в «косметическом» always-update ведре рядом с name/desc/sort. Если админ поменял бы
   `package_id` в рантайме — реверт на деплое. `package_id` структурен (какой пакет активирует
   тариф), не типичный рантайм-тюн как pv/price — риск низкий. Опционально: перенести `package_id`
   в create-only.

5. **[product_risk/Anthropic] Рекомендация: регресс-тест, что FeatureFlagSeeder НИКОГДА не пишет
   `mh_plan_v2_engine`** — залочить инвариант против будущих правок списка флагов. + Bronze 90→100
   — это рост цены для пользователя: держать в owner-gate чеклисте (не только в cutover-migrate).

## Тест-граунд-трус (перегнано, зелено)

`docker … php artisan test --filter='CutoverMigrateTest|ParityCheckTest|LedgerReconciliationTest'`
→ **14 passed (99 assertions), 1.21s.** Включая 5 новых red-тестов фиксов (tree-mismatch блок,
exit-nonzero, held-abort, held-surface, seeder-no-revert).

## Стоимость

**Оценочно ~$2–3** (per-call раннером не захвачен; включает орфан-повтор из первого прогона,
убитого 2-мин лимитом форграунда). Доминанта: Opus 4.8 ×~3–4 (architect/product_risk),
GPT-5.5-pro ×~4–5 пустых попыток (input оплачен) + GPT-5.5 fallback ×~2 успеха, DeepSeek v4-pro
(центы), Google — провал (незначимо). Packet ~26–28K input-токенов. Платный совет, standing
consent — карточка не показывалась.

**Деградация: НЕТ.** 3 разных вендора (Anthropic/DeepSeek/OpenAI) дали валидные ответы. Google не
ответил (документировано). DeepSeek — на обезличенном packet'е.
