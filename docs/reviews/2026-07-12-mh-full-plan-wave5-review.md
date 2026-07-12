# Ревью волны W5 (mh-full-plan) — совет моделей

**Дата:** 2026-07-12 · **Режим:** review-diff · **Диапазон:** `235ac88..60d309e` (release/mh-full-plan)
**Задачи:** T12 (возвраты/сторно, бэк) · T13 (админка V2, фронт) · T14 (Mini App «Мой план V2», фронт)
**Вердикт:** **request_changes** (5 must-fix). Все V2-флаги OFF — прод-поведение не меняется;
правки обязательны ДО T15-cutover / до включения флагов, не аварийно.

## Состав совета (кворум соблюдён — 3 вендора, НЕ degraded)
architect `claude-opus-4.8` · security `gpt-5.5` · correctness `claude-opus-4.8` (fallback, gpt-5.5-pro
не ответил) · tests `deepseek-v4-pro` · performance `deepseek-v4-pro` · maintainability `deepseek-v4-flash` ·
product_risk `gpt-5.5` (ретрай; sonnet-5 вернул пусто) · doc `deepseek-v4-flash`. Судья — оркестратор
(Claude Code) с прямым чтением кода. 31 находка после нормализации, 0 disagreements, 10 отброшено.
**Стоимость (оценка, без pre-snapshot):** ≈ $0.9–1.1 (драйвер — opus-4.8 на 2 ролях).

Секрет-редакция: 2 тест-фикстуры `whsec_*` заменены `«REDACTED»` до отправки; i18n `translation.json`
(2 файла, чистые строки) исключены из packet как шум.

---

## MUST-FIX (5)

### MF-W5-1 [high, деньги, ×3 — architecture+correctness+tests] — декремент `ns_month` при сторно НС неэффективен
`reverseBonusCredit()` штампует `meta.ns_month` на **DR**-ноге сторно, но
`WalletAccountsV2Service::executeForCalibratedMonth()` суммирует НС-ноги строго
`WHERE direction = CR` → DR-нога сторно НИКОГДА не вычитается из суммы месяца. Контракт-чек
W2+ №2 не выполнен. **Двойной провал:** вдобавок ни один путь T12 не вызывает
`reverseBonusCredit` на НС (и `BonusReversalService`, и `PeriodCorrectionService::post`
идут через `SUBACCOUNT_OS`) — механизм декремента не только сломан, но и не задействован.
Сценарий: начисление НС месяца M (CR), частичное сторно того же месяца до перевода → перевод
НС→ОС переносит **полную** сумму M, включая уже сторнированное (кап `min(monthCents, ns_cents)`
защищает только по плоскому балансу, не по-месячно).
**Фикс:** либо неттить `SUM(CASE WHEN direction=CR THEN +amt ELSE -amt END)` по `ns_month` в
`executeForCalibratedMonth`, либо ввести явный per-month bucket-счётчик, декрементируемый сторно;
+ красный тест within-month NS reversal → transfer.

### MF-W5-2 [high, деньги, correctness] — частичный возврат помечает ВЕСЬ заказ `refunded`
`RefundService::create()` (RefundService.php:127) безусловно ставит `order->status = REFUNDED`
даже для `kind=partial`. Последствия: (а) статус заказа искажён (вернули 1 из 3 позиций —
заказ «полностью возвращён»); (б) повторный частичный возврат остатка позиций упирается в
`status != paid` → 422 (RefundService.php:69/92). Достижимо через API (бэк принимает partial+lines).
**Фикс:** переводить в `REFUNDED` только при `kind=full` (или при полном покрытии всех позиций);
для partial — оставлять `paid`/вводить `partially_refunded`; тест на 2-й частичный возврат.

### MF-W5-3 [high, product] — частичный возврат нефункционален end-to-end
Админ-форма `RefundsV2View.js` (submit, стр. 64–66) шлёт только `order_id/kind/reason` — **без
`lines[]`**; бэк на partial с пустыми lines бросает «Пустой набор строк возврата» (422). Опция
«Частичный» в UI выбираема, но использовать её нельзя.
**Фикс:** добавить в форму ввод позиций (order_item_id+qty) для partial, слать `lines`; либо убрать
опцию partial из UI до готовности. Совмещается с MF-W5-2.

### MF-W5-4 [high, деньги/процесс] — сторно глобального бонуса молча пропущено
Полная подсистема `GlobalBonus*` существует, `ReversalAction::BONUS_GLOBAL='global'` объявлен, но
`BonusReversalService` обрабатывает только referral+structural+leadership — глобального пути нет,
теста нет, решения в доке нет. Фокус T12 требует реверс «по ВСЕМ бонусам». Тихий пропуск
предписанного шага (нарушение правила «не срезай алгоритм молча»).
**Фикс:** либо реализовать реверс/корректировку глобального (пул/квалификация — вероятно
period-correction, как структурный), либо **явно** зафиксировать owner-решение «глобальный вне
per-order реверса, т.к. пул/квалификационный агрегат» в коде (`RequalificationService`
qual_note) + доке + добавить тест, подтверждающий отсутствие проводки.

### MF-W5-5 [high, governance/security] — док кодирует standing-consent на необратимый money-cutover
`docs/specs/…-dec-triage.md` (добавлен в W5) вводит «Standing consent на PROD-гейт T15»: заранее
одобряет мердж release→main (прод-деплой) + флип `mh_plan_v2_engine ON` — сам док называет шаг 2
«необратимое денежное». Это ровно красная зона approval-гейта (мердж в main + money-engine флип),
которую по глобальным правилам нельзя пред-одобрять доком, исполняемым агентом/оператором.
Объективные предохранители (паритет, rollback-репетиция) полезны, но не заменяют свежее
подтверждение владельца в момент необратимого шага.
**Фикс:** переформулировать — consent покрывает подготовку/шаг 1 (деплой за флагами OFF); флип
движка ON (шаг 2) требует **свежего** owner-approve в момент исполнения, не doc-encoded.

---

## Чисто (проверено, находок нет) — фокусы 3 и 4
- **RBAC / deny-by-default:** `calculator.role` middleware — deny-by-default (owner проходит всегда,
  иначе 403). Mutation возвратов/корректировок = `owner`; read = `owner,finance` (routes/api/v2_refunds.php).
  Роль в middleware, не в комментариях. Negative-тесты присутствуют (RefundAdminApiTest).
- **IDOR:** cabinet/Mini App — member строго из `request->attributes('member')` (auth), id из запроса
  не принимается; лид → 404 (не раскрывает). `{account}` валидируется `[a-z]+` + whitelist.
- **Флаги OFF:** `mh_v2_refunds` / `mh_plan_v2_admin` / `mh_plan_v2_miniapp` — deny-by-default; фронт
  фильтрует nav по карте флагов; OrderService.php guard блокирует прямой refund мимо RefundService.
- **Словарь MF-8:** award-статусы granted/on_hold/paid_out/forfeited; policy draft/active/retired
  (нет APPROVED). Фронт своих имён не вводит.
- **Не двойная правда о ранге:** фронт читает `current_rank_code` из бэка (mmPlanRankProgress), не
  пересчитывает.
- **Секреты:** initData/bearer не логируются (нет `console.*` с токеном/initData в диффе).
- **Движок / стыки:** V1 `Modules/Calculator/Domain` не тронут; реверс считается по иммутабельному
  снапшоту (`v2_order_volume_snapshots`, ReversalPlanner), не по текущему каталогу/тиру; идемпотентность
  сторно — `firstOrCreate`/`alreadyPosted` по ключам `v2:reversal:*`; каскад закрытых периодов —
  proposed→approved→posted без reopen; ранг/награда/тир не отзываются (RequalificationService — только
  аудит-записи). Advisory ACTIVATION_LOCK берётся оркестратором (W2+ №4).

---

## NICE-TO-HAVE (не блокеры)
1. [medium] Миграции down() = `dropIfExists` на `v2_order_returns/return_lines/reversal_actions/period_corrections`
   — откат уничтожает финансовые/аудит-записи. Forward-only дисциплина репо это смягчает, но безопаснее
   пустой down() или guard.
2. [medium] `PeriodCorrectionService::post()` дебетует ОС всегда; нет ветки «текущий (не откалиброванный)
   месяц НС» из W2+ №5 — если структурный closed-периода был на НС и не переведён, ОС-дебет создаёт
   clawback при деньгах на НС. Достижимость низкая (closed → откалиброван), но стоит покрыть тестом.
3. [medium] MiniApp `accounts/{account}/lots` — `->get()` без лимита/пагинации (unbounded).
4. [medium] Тест-дыры: реверс лидерского через period-correction; идемпотентность 2 частичных возвратов;
   recovery clawback-долга; отсутствие проводки для глобального (см. MF-W5-4).
5. [low] Дублирование фронта: `usd`/`dt`/`isOwner` локально в PoolReport/RewardsQueue/PolicyVersions
   вместо `format.js`; `apiV2.js` форкает auth/401-логику webApi (расходится с `refundsApi.js` на `req()`).

---

_Артефакты: `.review/packet.md`, `.review/raw5/*.json`, `.review/consensus5.json`._

---

## Fixloop W5 — верифай закрытия 5 must-fix (2026-07-12)

**Режим:** review-fixloop · **Диапазон:** `60d309e..e3cdf4d` (5 фикс-коммитов) · **Вердикт: acceptable.**
Все 5 must-fix закрыты корректно. Целевой прогон тестов зелёный, красные→зелёные регрессы на месте.

**Состав совета (кворум соблюдён — 3 РАЗНЫХ вендора с валидными ответами, НЕ degraded):**
correctness `openai/gpt-5.5` · security `anthropic/claude-opus-4.8` · product_risk + tests
`deepseek/deepseek-v4-pro`. Судья — оркестратор (Claude Code) с прямым чтением кода.
gemini-3.1-pro (non-JSON ×5) и gpt-5.5-pro (empty/reasoning ×2) отвалились на product_risk —
seat закрыт deepseek-fallback'ом; диверсити вендоров (openai+anthropic+deepseek) сохранён.
Секрет-редакция: дифф чист (money-логика + тесты, ни `whsec_*`/токенов/PII) — исключать нечего.
**Стоимость (оценка, token-based; чистого per-run delta нет):** ≈ **$0.6**
(драйвер — retry-и на gpt-5.5-pro/gemini). Платный совет, standing consent. Фолбэк на
собственную критику НЕ применялся (платный доступен).

**Ground-truth тесты (docker izigo-php-dev + izigo-test-pg):** `RefundAdminApiTest` +
`GlobalBonusReturnReversalTest` + `NsToOsTransferV2Test` → **30 passed (118 assertions)**.
Красные→зелёные, покрывающие фиксы: `testWithinMonthReversalIsNettedNotTransferred`,
`testOpenPeriodStructuralReversesOnNsWithoutCorrection` (MF-W5-1), `testPartialReturnsCumulative
CoverageMarkRefundedOnlyWhenFull` + `testPartialWithoutLinesRejectedWithClearMessage` (MF-W5-2/3),
`testDraftMonthEligibleBvReducedByReturn` / `…RecomputesDraftMonthAndRecordsNote` /
`…FinalMonthIsOwnerManualNotAutoPosted` (MF-W5-4). Полный сьют не гонялся (targeted зелёные).

**Пофайндингово (закрытие):**
- **MF-W5-1 [деньги] — ЗАКРЫТ.** `executeForCalibratedMonth` неттит `SUM(CASE direction=CR +amt
  ELSE -amt)` по `ns_month` (DR-нога сторно вычитается). `reverseStructuralOnNs` дебетует НС
  открытого периода сырой `after_cap`-долей с `accrualMonth=struct.accrual_month` (штампует
  ns_month на DR). Кросс-месячная изоляция доказана тестом (сторно июля не крадёт из августа).
  Предикат `!isClosed()`→НС / closed→OS согласован; edge «closing→НС-путь» безопасен —
  `reverseBonusCredit` при нехватке НС уводит долг в clawback→OS, не течёт.
- **MF-W5-2 [деньги] — ЗАКРЫТ.** `isFullyReturned()` суммирует `OrderReturnLine.qty` по
  `order_item_id` через ВСЕ возвраты (включая только что созданный в той же транзакции) vs
  `OrderItem.qty`; refunded только при полном покрытии. Двойного зачёта нет (SUM per-item);
  over-return отсечён апстримом (ReversalPlanner qty>ordered→reject). 2 partial → refunded на 2-м.
- **MF-W5-3 [process] — ЗАКРЫТ.** Фронт строит `lines[]` из `Form.List` (фильтр пустых, `Number()`),
  бэк 422 «Частичный возврат требует непустой список позиций (lines[])». e2e зелёный.
- **MF-W5-4 [деньги] — ЗАКРЫТ.** `base = ΣPAID_bv − Σreturned_bv` по окну, durable и
  идемпотентно (SUM по строкам возвратов при каждом `allocateForMonth`, не инкремент → нет
  двойного вычета). Draft → пересчёт долей; final/paid → owner-manual note, БЕЗ авто-проводки
  (тест: allocation нетронута, 0 payout-строк). Утечки draft→финализация нет (база пересчитывается,
  не декрементится).
- **MF-W5-5 [governance] — ЗАКРЫТ.** Раздел consent разделён: шаг 1 (мердж→деплой за флагами OFF) —
  standing consent; шаг 2 (флип `mh_plan_v2_engine` ON) — явно «НЕ покрыт standing consent'ом и НЕ
  doc-encoded», требует свежего owner-«go» в момент исполнения через permission-систему.
  Doc-encoded автономный необратимый money-cutover устранён. Security-seat находок не дал.

**Новое (nice-to-have, НЕ блокер, вскрыто на фиксе MF-W5-4):**
- [medium] `BonusReversalService::recordGlobalNote()` пишет `target_id = $monthPeriod->id`
  (calc-period id) при `target_type='global_bonus_month'`, тогда как реальный PK строки —
  `$month->id` (загружен на строке 310, но использован только для `isFinal()`). Провенанс-указатель
  ссылается на период, а не на `GlobalBonusMonth` → follow-up ручной обработки финализированного
  месяца может уткнуться в неверный id. Тесты матчат по `return_id`+`bonus_type`, потому зелёные.
  Фикс: передавать `$month?->id` в `recordGlobalNote` (period id — в `snapshot_json`). Money-математику
  и идемпотентность (ключ `v2:reversal:{return}:global`) не задевает.

_Артефакты: `.review/packet_w5fix.md`, `.review/rawW5fix/*.json`._
