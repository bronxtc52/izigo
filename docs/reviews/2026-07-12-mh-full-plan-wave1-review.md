# Review report — review-diff (интеграционное, волна W1) · binar-mlm @ release/mh-full-plan (a4a11e4)

> **Вердикт: `request_changes`** · Council: 7 ролей × 3 батча (21 прогон) · Judge: openai/gpt-5.5 · Preset: balanced · 2026-07-12
> Скоуп: `git diff main...release/mh-full-plan` — скаффолд V2 + T01 (конфиг политики) + T02 (счета ОС/НС/БС) + T03 (PV/BV-лоты) + T04 (периоды); 151 файл, +14041 строк.
> Контракты волны: `docs/specs/2026-07-12-mh-full-plan-gateA-amendments.md`. main не тронут; всё за выключенными фиче-флагами — вердикт блокирует мердж волны/включение флагов, прод сейчас не под угрозой.

## Summary (judge)

Выжили блокирующие дефекты денег/provenance: доменный объект PolicyV2 отдаёт аксессоры-методы
(`versionId()`/`configHash()`), а несколько потребителей читают несуществующие свойства
`->id`/`->config_hash` — provenance политики молча теряется или замирает на fallback=1.
Валидатор калибровки не запрещает awards в числителе (нарушение MF-1/2), в кошельке/заказах —
конкретные гонка и period-scoping баг. Остальное — производительность, консистентность admin-ответов,
тестовая/док-гигиена (не блокирует).

## Must-fix (7) — блокируют мердж волны

### [MF-1] Потребители PolicyV2 читают несуществующие `id`/`config_hash` → provenance всегда fallback (шов T01↔T03/T04)
`high` · confidence high · подтверждений 3 (correctness, architect, maintainability) + верификация оркестратора по коду
**Где:** `V2/Services/Volume/PolicyVersionIdProvider.php:43-56` (+ та же ошибка: `V2/Services/Periods/PeriodService.php:123` и `V2/Services/Periods/SnapshotService.php:96-105` — см. MF-2)
**Evidence:** `PolicyV2` (T01) экспонирует `versionId()`/`configHash()` методами с приватными свойствами. `PolicyVersionIdProvider` проверяет `method_exists($policy,'id')` / `property_exists($policy,'id')` — оба false → **всегда** молча возвращает `FALLBACK_VERSION_ID=1`, даже когда резолвер T01 забинден и активна другая версия. Тестовый дублёр `Tests/Feature/V2/Support/FakePolicy` объявляет `public $id/$config_hash` («как у домена T01» — неправда) и маскирует баг: 606 зелёных тестов это не ловят.
**Почему важно:** provenance каждого PV-лота/снапшота (обязателен по MF-5 amendments для аудита денежных расчётов T06–T11) будет навсегда штампован version_id=1 независимо от активной политики.
**Фикс:** читать `$policy->versionId()` (контракт MF-5); fallback — только когда резолвер не забинден/нет активной версии, с warning. FakePolicy привести к реальному API (`versionId()`/`configHash()`), добавить интеграционный тест provenance с реальным `PolicyVersionService`.

### [MF-2] Периоды/снапшоты теряют policy provenance той же ошибкой (шов T01↔T04)
`high` · confidence high · confirmed correctness + верификация оркестратора
**Где:** `V2/Services/Periods/PeriodService.php:123` (`$policy->id ?? null`), `V2/Services/Periods/SnapshotService.php:96-105` (`$policy->id ?? …`, `$policy->config_hash ?? null`)
**Evidence:** те же обращения к несуществующим публичным свойствам — `??` глушит недоступность → `v2_calc_periods.policy_version_id` всегда NULL, секция `policy` снапшота всегда `{policy_version_id: null|старое, config_hash: null}`.
**Почему важно:** снапшот закрытия периода обязан фиксировать `versionId()/configHash()` (docblock самого PolicyV2); без этого расчёты T06–T11 невоспроизводимы/неаудируемы.
**Фикс:** `versionId()`/`configHash()` + тест, что после активации версии период/снапшот получают реальные id/hash.

### [MF-3] `executeForCalibratedMonth` переводит ВЕСЬ плоский НС, а не откалиброванный месяц (шов T02↔T04, MF-4)
`high` · confidence high · confirmed correctness
**Где:** `V2/Services/Wallet/WalletAccountsV2Service.php:151-174`
**Evidence:** `where('ns_cents','>',0)` → `$raw = $account->ns_cents` — без месячного скоупа; контракт `NsToOsTransfer` явно требует «за оба полумесяца откалиброванного месяца». Если калибровка месяца M закоммичена после закрытия H1 месяца M+1 (T11 задержался/ручная калибровка), начисления M+1 на НС уедут в ОС под factor_bps месяца M.
**Почему важно:** досрочная доступность структурной премии + неверный фактор калибровки = прямое искажение денег.
**Фикс:** месячная атрибуция НС-начислений (meta месяца в ledger-проводках НС от T06 + суммирование по месяцу при переводе), либо задокументированный жёсткий инвариант тайминга с guard'ом (перевод M блокируется, если после ends_at(M) уже были НС-кредиты за M+1). Решить ДО мерджа T06/T11 (W2).

### [MF-4] Валидатор не запрещает awards в числителе калибровки (нарушение MF-1/2 amendments)
`high` · confidence high · confirmed correctness · верифицировано оркестратором
**Где:** `V2/Services/PolicyConfigValidator.php:455-460`
**Evidence:** enforce'ится только `include.leadership === false`; `include.awards = true` проходит валидацию (дефолт false, но owner может сохранить и активировать конфиг с true).
**Почему важно:** T11 посчитает factor_bps по неканоническому числителю → неверные выплаты всем партнёрам; канон MF-1/2 — awards исключены решением владельца.
**Фикс:** зеркальная жёсткая проверка `calibration.include.awards === false` + негативный тест.

### [MF-5] AccountsPolicyV2 захардкожен и не читает политику T01 (шов T01↔T02 не замкнут в волне)
`high` · confidence high · подтверждений 2 (architect, maintainability)
**Где:** `V2/Services/Wallet/AccountsPolicyV2.php:12-35`
**Evidence:** 7000 bp / 365 дней хардкодом с `TODO(T01-merge)` — а T01 смерджен этой же волной; изменение accounts.* в активированной политике не влияет на лимит оплаты с ОС и сроки лотов, при этом снапшоты будут заявлять версию политики.
**Фикс:** инжект `PolicyVersionResolver`, значения из `forDate($at)->accounts()`; хардкод — только как fail-safe дефолт. Если сознательно откладывается на W2 — зафиксировать явным решением в плане волны, не молчаливым TODO.

### [MF-6] Гонка «резерв со счетов vs TON-инвойс» на одном заказе → переплата
`high` · confidence high · confirmed correctness
**Где:** `V2/Services/Wallet/OrderAccountPaymentService.php:78-100` + `Services/PaymentService.php:51-67`
**Evidence:** `reserve()` проверяет живой инвойс без блокировки заказа; `startOrderPayment` считает remainder без блокировки резервов — конкурентные запросы (double-click) проходят обе проверки: инвойс на полную сумму поверх резерва.
**Почему важно:** реальная переплата участника + лишние проводки company_sales_revenue.
**Фикс:** единый row-lock заказа (`Order lockForUpdate`) в начале обеих операций, проверки «живой инвойс/живой резерв» — после взятия лока; negative-тест на гонку.

### [MF-7] Мутации PV-лотов вне оплаты не проверяют ACTIVATION_LOCK (amendments, дисциплина локов #5)
`high` · confidence medium · architect (minority, in-lane) · частично верифицировано оркестратором
**Где:** `V2/Services/Volume/PvLotVolumeService.php:48-61` (`runMatchingForPeriod`), admin-триггер `VolumeAdminController::runMatching`, `PvLotIngestService::reverseUnmatchedLotsForOrder`
**Evidence:** `recordPaidOrder` зовёт `assertLockHeld()`, а периодный/ручной матчинг и reversal — нет; ручной прогон матчинга из админки конкурирует с инжестом лотов оплаты (row-locks есть, но сериализация с V1-пересчётом по контракту волны — через advisory-lock).
**Фикс:** оркестраторы этих операций берут `pg_advisory_xact_lock(ACTIVATION_LOCK_KEY)` в транзакции; внутренние сервисы — `assertLockHeld()`.

## Nice-to-have (8, judge-kept)

- **[NTH-1] SnapshotService: манифест оплат периода целиком в память/JSON** — `high`/high, `SnapshotService.php:113-125`. На объёме — медленное закрытие/OOM; курсор + агрегаты или отдельная таблица манифеста. (Держать на радаре до cutover T15.)
- **[NTH-2] Индекс по `v2_pv_lots.occurred_at`** — `medium`/high, полный скан в `runMatchingForPeriod` (композитный индекс начинается с owner_member_id; верифицировано по миграции 120200).
- **[NTH-3] `expireLots()` без чанкования** — `medium`/high, `WalletAccountsV2Service.php:277-282`; `chunkById`.
- **[NTH-4] Идемпотентность credit/debit не атомарна под гонкой** — `medium`/high: `alreadyPosted` до лока; при дубле параллельно второй падает unique violation вместо no-op (денег не задваивает). Re-check после `lockAccount` или catch 23505 в `post()`.
- **[NTH-5] Ключ кэша резолва политики без таймзоны** — `medium`/high, `PolicyVersionService.php:47-50`; нормализовать в UTC-instant.
- **[NTH-6] `show()` периода не грузит `runs_count`** — `medium`/high, `PeriodAdminController.php:51` (в ответе runs=[…], runs_count=0; верифицировано).
- **[NTH-7] Тестовые биндинги контрактов без отката** — `nit`, `PeriodCloseOrderingTest.php` (`$this->swap()` / tearDown).
- **[NTH-8] README: раздел установки V2 (флаги/миграции)** — `low`.

## Примечания оркестратора (вне вердикта судьи, верифицировано по коду)

1. **PeriodCalendar/PvLotVolumeService: месяц `13` проходит regex `(\d{2})`** — Carbon нормализует `2026-13` в `2027-01` → период/cutoff не того окна. Находка correctness была отсечена механическим капом «10 находок на ревьюера», факт проверен. Экспозиция низкая (коды приходят из БД/расписания), фикс дешёвый: `0[1-9]|1[0-2]` в `fromCode()`/`cutoffForPeriod()`.
2. **`LedgerV2::credit()` без `sourceType/sourceId`** — судья дропнул как «speculative» (ломается только у будущих потребителей). Для волны, чей продукт — контракты W2, рекомендую расширить интерфейс сейчас: T06–T10 иначе завяжутся на конкретный класс. Дёшево, пока потребителей нет.
3. **Партиал-unique на «одна active-версия» в `v2_policy_versions`** — судья дропнул (сервис сериализует lockForUpdate); как defense-in-depth для денег — дешёвый индекс `WHERE status='active'`. На усмотрение.

## Disagreements (1, разрешён судьёй)

- **[maintainability-1]** high-blocking «wrong method → fallback 1» vs low «dead code id()-проверок» → **ruling:** kept high, merged в MF-1 («fallback достижим с реальным PolicyV2 и портит money provenance — это не мёртвый код»).

## Dropped by judge (38) — аудит

Ключевые: «Missing test executeForCalibratedMonth» и «Seed mh_plan_v2_periods отсутствует» — **false positive кросс-батчинга** (тест `NsToOsTransferV2Test` и миграция `130400` существуют, батч C); «unused $activation» — FP (используется в замыкании); README/CHANGELOG/docs-api — конвенций в репо нет; coverage-gap-only тест-находки — tests-noise rail; полный список с причинами — `.review/consensus.final.json` (`dropped[]`).

## Прогон / методика

- **Батчи** (дифф 990 KB > лимит 300 KB): A = T02+T03 (шов леджер↔лоты в одном пакете, 291 KB), B = T01+скаффолд (189 KB), C = T04+DI/расписание (191 KB). Все контракты `V2/Contracts/*` + amendments + seam-выжимки соседних батчей — в каждом пакете. Кросс-батч FP отфильтрованы судьёй по верифицированным фактам оркестратора.
- **Роли/модели:** architect → gpt-5.5 (A,B) / deepseek-v4-pro (C); security, correctness → gpt-5.5; tests, performance → deepseek-v4-pro; maintainability → v4-flash/v4-pro; doc → v4-flash; judge → gpt-5.5. Кворум 7/7 ролей — **не деградирован**.
- **Инфра-заметки:** `anthropic/claude-sonnet-5` и `gemini-3.1-pro-preview` стабильно отдавали пустой/не-JSON контент на 200-KB пакетах (architect и judge доехали на fallback). Diversity-предупреждение: architect A/B и judge — один вендор (gpt-5.5) с correctness/security; ключевой кластер MF-1/2 дополнительно подтверждён deepseek (maintainability) и верификацией оркестратора по коду.
- **Redaction:** пакеты прогнаны по `security.redact_patterns` (5 замен — тестовые заглушки `test-webhook-secret`); PII/секретов в диффе нет; `or-sanitize` не использован намеренно — его b64/phone-маски уничтожали пути файлов и даты миграций (зафиксировано).
- security-роль: 0 находок по всем батчам (роуты V2 — фиче-флаги + `calculator.role`-middleware по amendments #1, IDOR-скоуп оплаты верифицирован) — валидный исход.

## Стоимость

**$9.72** (OpenRouter, весь прогон: 21 роль-вызов + ретраи architect/tests/maintainability + judge; usage 302.25 → 311.97). Оплачено по standing consent на платное ревью; записать в леджер сессии.

---
_Артефакты: `.review/consensus.final.json` (вердикт судьи, машиночитаемый), `.review/consensus.json` (до-judge агрегация), `.review/packet-{A,B,C}.red.md` (что видели ревьюеры), `.review/raw/*.json` (сырые ответы ролей). Прошлый прогон Гейта A перенесён в `.review/history/2026-07-12-gateA/`._

---

# Fixloop W1 — верификация фиксов (раунд 2)

> **Режим:** review-fixloop · **Дата:** 2026-07-12 · **Вход:** fixlist MF-1..MF-7 (verbatim) + дифф фиксов `a4a11e4..7443e99` (коммиты b00db5b..7b91be0, U10, 116 KB) · сьют 629 passed
> **Состав (сокращённый):** correctness = `anthropic/claude-opus-4.8`, security = `openai/gpt-5.5` (0 находок — валидный исход); chair — оркестратор по judge.md. Redaction-скан диффа — чисто.
> **Стоимость раунда:** **$0.57** (OpenRouter, 311.97 → 312.54). Фолбэк-режим: не применялся.
> Артефакты: `.review/packet-w1fix.md`, `.review/raw/w1f_*.json`.

## Вердикт раунда: `approve_with_notes` — ACCEPTABLE, волна W1 допускается к мерджу

## Статусы must-fix — 7/7 confirmed_fixed

| # | Находка | Статус | Подтверждение |
|---|---|---|---|
| MF-1 | PolicyV2 `->id`/`->config_hash` → fallback=1 | ✅ confirmed_fixed | потребители читают `versionId()`/`configHash()`; `FakePolicy(versionId:, configHash:)` приведён к реальному API; `PolicyProvenanceTest` (108 строк) проверяет provenance с реальным PolicyVersionService (chair, textual + тест в диффе) |
| MF-2 | периоды/снапшоты теряют provenance | ✅ confirmed_fixed | PeriodService/SnapshotService на `versionId()`/`configHash()`; ассерты «снапшот без versionId() политики» в тестах |
| MF-3 | перевод всего плоского НС вместо месяца | ✅ confirmed_fixed | `credit()` штампует `meta.ns_month` (обязательно для НС, default = месяц now() UTC, формат-валидация); `executeForCalibratedMonth` суммирует строго по `ns_month=месяц`, идемпотентен по (member, month), `min(month, ns_cents)`-guard с warning-логом дрейфа; дельта калибровки (raw−paid) — в sink `company_pool_retained`, двойная запись сходится |
| MF-4 | валидатор не запрещал awards в числителе | ✅ confirmed_fixed (совет, explicit) | зеркальный guard `include.awards === false` + негативный тест; null-safety симметрична leadership (bool-валидация до guard'а) |
| MF-5 | AccountsPolicyV2 хардкод | ✅ confirmed_fixed | резолвер `forDate($at)->accounts()`, fail-safe дефолты из DefaultPolicyConfig с warning (не молча) |
| MF-6 | гонка «резерв vs TON-инвойс» | ✅ confirmed_fixed (совет, explicit) | `Order lockForUpdate` ПЕРВЫМ в обеих точках входа (`reserve()` и `startOrderPayment()`), проверки живого инвойса/резерва — после лока; порядок локов консистентен (Order → reservation/account) во всех трёх показанных потоках — противоположного порядка совет не нашёл |
| MF-7 | мутации PV-лотов без ACTIVATION_LOCK | ✅ confirmed_fixed (в объёме W1) | `runMatchingForPeriod`/`reverseUnmatchedLotsForOrder` — `assertLockHeld()`; admin `runMatching` берёт `acquireActivationLock()` в транзакции; `VolumeLockDisciplineTest` (negative). У `reverseUnmatchedLotsForOrder` в W1 нет продакшен-вызывающих (верифицировано grep) — обязательство оркестратора ложится на T12 (см. notes) |

**Спец-вопросы оператора:**
- **MF-3 / старые НС-начисления без ns_month:** совет пометил blocking-риск «stranded NS»; chair **дропнул как unreachable с аудируемым обоснованием**: ветка release/mh-full-plan не деплоилась, флаги off, T06 (единственный источник НС-кредитов по плану) не смерджен — популяция до-фиксовых НС-проводок пуста во всех реальных окружениях; после фикса `credit()` штампует `ns_month` безусловно (unstamped-строки создать нельзя). Инвариант закреплён в notes как гейт W2.
- **MF-6 / дедлок-безопасность:** обе точки входа берут row-lock заказа первым, порядок Order → reservation/account консистентен; замечание совета (low) — задокументировать канонический порядок локов — в notes.

## Notes (не блокируют, переходят в гейт W2)

1. **НС-инвариант для W2 (из blocking-находки совета, разжалованной chair'ом):** НС кредитуется ТОЛЬКО через `LedgerV2::credit()` (auto-stamp `ns_month`); T06 не имеет права постить `ACC_NS`-ноги напрямую через `LedgerPostingV2Service::post()`. Внести в контракт-чек мерджа W2 + дешёвый residue-guard (алерт, если `ns_cents` ≠ Σ непереведённых месячных бакетов).
2. **Месячные суммы = gross-кредиты (совет, medium):** сейчас корректно — иных дебетов НС, кроме самого перевода, в коде нет (верифицировано grep); если T12 (возвраты) введёт дебеты НС — обязателен подекремент месячных бакетов, иначе перевод завысит месяц. Зафиксировать в контракте T12.
3. **Канонический порядок локов** (совет, low): задокументировать «advisory ACTIVATION_LOCK → Order row-lock → account/lot lock» в README V2 / контракте волны.
4. **T12-оркестратор reversal'ов** обязан брать `pg_advisory_xact_lock` до `reverseUnmatchedLotsForOrder` (positive-тест под held-lock) — гейт W2.
5. **Примечание оркестратора (вне вердикта совета):** идемпотентность перевода по ключу `(member, month)` означает, что НС-кредит, застампованный УЖЕ переведённым месяцем M (поздняя корректировка), останется на НС навсегда — по контракту таймингов такого пути в W1–W2 нет (T06 постит до закрытия месяца), но корректировки T12 не должны кредитовать НС прошлыми месяцами. Добавить в контракт-чек T12.

## Итог

`request_changes` (интеграционное ревью W1) → 7 фиксов → `approve_with_notes`. Мердж волны W1 в release/mh-full-plan допущен; notes 1–5 — обязательные пункты контракт-чека гейта W2 (T06/T11/T12).
