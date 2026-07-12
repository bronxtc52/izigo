# Review report — review-diff (интеграционное, волна W2 = T05) · binar-mlm @ release/mh-full-plan (bf0e0f7)

> **Вердикт: `request_changes`** (1 must-fix) · Council: 7 ролей (architecture, security, correctness, tests, performance, maintainability + judge) · Preset: strong-mix · 2026-07-12
> Скоуп: дифф `803b77d~1..bf0e0f7` — задача **T05** (лестница 12 статусов + CLIENT/grace + тиры), 42 файла бэкенда, +3346 строк. Сьют 666 passed.
> Контракты волны: `docs/specs/2026-07-12-mh-full-plan-gateA-amendments.md` (+ W2+ контракт-чеки). main не тронут; вся T05 за флагом `mh_v2_statuses` (OFF) — вердикт блокирует включение флага/приёмку T05, прод сейчас не под угрозой.
> **Модели:** architect → `claude-opus-4.8`; security, correctness → `gpt-5.5`; tests, performance, maintainability → `deepseek-v4-pro`; judge → `claude-opus-4.8`. Кворум 7/7 — **не деградирован**. Фолбэк-режим: не применялся.
> **Стоимость: $1.59** (OpenRouter, usage 312.54 → 314.13). Артефакты: `.review/consensus-w2.json`, `.review/raw/w2_*.json`, `.review/packet-w2t05.md`.

## Summary (judge)

Механика рангов/тиров/CLIENT-grace структурно добротная и за выключенным флагом, но один конкретный дефект консистентности данных пережил фильтр: PV-лоты, пришедшие в окно между дедлайном grace и прогоном сканера, ставятся в `grace_held` и больше никогда не разрешаются — PV застревает навсегда (money-adjacent инвариант). Остальное — пробелы тест-покрытия и заметки по архитектурной ясности без доказанного дефекта в этом диффе.

## Must-fix (1) — блокирует приёмку T05

### [MF-1] Grace-hold лотов уже просроченного CLIENT → PV застревает навсегда (money-adjacent)
`high` · confidence high · correctness (single-reviewer, concrete reproducible path — judge оставил как must-fix)
**Где:** `V2/Services/Status/ClientLifecycleService.php:99-113` (`holdIncomingLotsForGraceClients`)
**Evidence:** hold-запрос фильтрует только `state='client' AND grace_outcome IS NULL`, БЕЗ проверки `grace_expires_at`. Сценарий: у CLIENT `grace_expires_at = 2026-08-04 17:59:59 UTC`; сканер ещё не прогонялся; в `2026-08-04 18:05:00 UTC` заказ даунлайна создаёт FREE-лот этого владельца. `holdIncomingLotsForGraceClients()` переводит его в `grace_held`, хотя окно grace уже закрыто. Потом `expireGrace()` зовёт аннулирование с `until = grace_expires_at`, а `GracePvLotAnnulmentService` фильтрует `occurred_at <= until` (стр. 37-45) — лот от 18:05 НЕ аннулируется. И `succeedGrace` (освобождение grace_held→free) выполнится только при появлении реферала. Итог: лот навечно в `grace_held` — матчинг T03 его не берёт, ранг/branch-stats не учитывают, аннулирование не достаёт.
**Почему важно:** PV в окне «дедлайн … прогон сканера» необратимо застревает, искажая branch-state на money-adjacent величине (PV определяет пороги лестницы → ставки 5-9%, капы, глубину лидерского).
**Фикс:** исключать просроченные grace-строки при hold, опираясь на `paid_at` заказа, а не на время скана. Передавать `$paidAt` в `holdIncomingLotsForGraceClients()` и держать только владельцев с `grace_expires_at >= $paidAt`; просроченные должны проходить через `expireGrace()` ДО любого hold (в `StatusesStep::handle` порядок: expire-if-due → hold). Добавить negative-тест на лот в окне «после дедлайна, до скана».

## Nice-to-have (10)

- **[architect-1]** `medium/high` — `rankAsOf`/`tierAsOf` сортируют по ordinal, опираясь на НЕобеспеченный инвариант монотонности achieved_at. Сейчас работает (ранг навсегда, скачок пишет один achieved_at), но контракт StatusReader этого не гарантирует. Зафиксировать инвариант (DB-check/guard) либо резолвить как `max(rank_ordinal) where achieved_at<=$at`. (Не дефект в этом диффе — потому nice, а не must.)
- **[architect-3]** `medium/low` — лоты, попавшие в `grace_held` прямо перед коммитом expiry, могут ускользнуть от `occurred_at<=until`-аннулирования. Родственно MF-1: на терминальном исходе grace надёжнее аннулировать ВСЕ `grace_held`-лоты участника (или пост-expiry sweep остатка), чем полагаться на окно occurred_at. **Рекомендуется закрыть вместе с MF-1.**
- **[performance-1]** `medium/high` — `evaluateAffectedUpline` делает полный BFS поддерева на КАЖДОГО предка → O(depth²) запросов на заказ. На проде 5 участников незаметно, но hot-path пайплайна: построить subtree-map один раз (recursive CTE для верхнего предка) и переиспользовать по цепочке.
- **[architect-5]** `low/medium` — гейт `evaluateMember` кодирует «is consultant» дважды (state-строка + rank ordinal); выбрать один источник истины (`current_rank ordinal >= CONSULTANT`) или задокументировать, почему оба и что не расходятся.
- **[architect-2]** `low/medium` — `tierAsOf`/`rankAsOf` игнорируют `policy_version`; зафиксировать в контракте StatusReader инвариант «коды рангов/тиров append-only, не ремапятся между версиями политики → as-of version-agnostic».
- **[tests-2]** `medium` — нет unit-теста DistinctBranchAssigner для anchorCount=2 + comparator=exact + конфликт общей ветви (два кандидата одной ветви → null; в разных → pass).
- **[tests-6]** `medium` — нет теста «тир не понижается при реверсе PV» (ELITE@600 → реверс ниже 600 → тир остаётся ELITE, строки-даунгрейда нет).
- **[tests-1]** `medium` — нет интеграционного теста гонки grace-скан ↔ активация реферала после дедлайна (аннулирование ровно один раз, PV-сумма верна). Примечание: сам код гонку закрывает (`expireGrace` идемпотентен через `grace_outcome`-guard + `lockForUpdate`) — это пробел покрытия, не дефект.
- **[tests-4]** `low/high` — нет теста идемпотентности `grace_expired→consultant` при повторном `onPersonalReferralActivated` (rank_history=2, state=CONSULTANT).
- **[tests-3]** `low/medium` — тест `evaluateAffectedUpline` не проверяет, что неквалифицирующиеся предки остались без изменений (все evaluations passed=false, current_rank_code не двинулся).

## Проверка фокус-областей ревью (оркестратор, верифицировано по коду)

1. **Квалификация 3 вариантов / различные корневые ветви / Директор S38** — ✅ корректно. Голден `DistinctBranchAssignerTest::testDirectorS38TwoGoldSameRootBranchGiveOneSlot` покрывает «два Gold в одной ветви = один слот» (4 ветви < 5 → fail; +новая ветвь → pass). `pickAnchors` перебирает все anchor-комбинации (глубина ≤ anchorCount ≤ 2), support = оставшиеся ПОПАРНО РАЗНЫЕ ветви; «кандидат не дважды», anchor-only-L1, детерминизм (перестановка входа) — все с тестами. Совет добавил только coverage-заметку по exact+2-anchor (tests-2).
2. **CLIENT grace** — канон amendments MF-7 соблюдён (state=`client` + `grace_expires_at`, отдельного `client_grace` нет; сканер `state='client' AND grace_expires_at < now() AND grace_outcome IS NULL`). Дедлайн Asia/Almaty end-of-day→UTC (DEC-006/026 вариант B). Гонка «реферал после дедлайна до скана» закрыта expire-then-continue + идемпотентный `expireGrace`. **Единственная дыра — MF-1** (hold после дедлайна).
3. **«Ранг навсегда» (DEC-020/040)** — ✅ `recordAchievedRanks` пишет строку на каждый пройденный ранг [current+1 … achieved] с одним `evaluation_id`; `unique(member_id, rank_code)` (миграция 100300) даёт идемпотентность; current_rank_code только вверх. `TierService` — тир только вверх по ordinal каталога, `unique(member_id, tier)`.
4. **Дисциплина локов** — ✅ `assertLockHeld()` на всех мутирующих статус-сервисах (RankEvaluationService, ClientLifecycleService, TierService, GracePvLotAnnulmentService); оркестраторы берут `acquireActivationLock()` в транзакции (`StatusAdminController::recompute`, `ClientGraceScanCommand`); `StatusesStep` работает внутри markPaid, держащего advisory-lock. Порядок локов не нарушает канон amendments #3. Мутаций PV-лотов без guard совет не нашёл.
5. **deny-by-default / RBAC / IDOR** — ✅ security-роль (gpt-5.5): 0 находок. Вся группа `v2_statuses.php` за `feature.flag:mh_v2_statuses`; cabinet — `telegram.auth`, member из auth-атрибута (chosen id игнорируется — IDOR закрыт); admin read `calculator.role:owner,finance`, mutation (`recompute`) `owner`-only; recompute пишет аудит-строку.
6. **Контракт-чеки W2+ (amendments)** — T05 не трогает НС/возвраты; общий чек #3 (ACTIVATION_LOCK только оркестратором) соблюдён.

## Disagreements

Нет (0).

## Dropped судьёй (3)

- **[architect-4]** StatusesStep re-derives paidAt/policy — unsupported (ниже minority-порога, нет конкретного пути расхождения).
- **[architect-6]** per-order BFS — duplicate (слит в performance-1).
- **[tests-5]** boundary-тест grace+1 сек — speculative (ниже порога, инклюзивная математика DEC-006/026 без дефекта).

## Прогон / методика

- Дифф 164 KB < лимита 300 KB — один пакет (без батчинга). В header — карта T05, seam'ы W1 (PaidOrderV2Pipeline/BranchStatsService/PolicyVersionResolver), фокус-области, W2+ контракт-чеки. Redaction-скан — чисто (PII/секретов нет; T05 бэкенд-only).
- **Инфра-заметки:** architect доехал на `claude-opus-4.8` (первичная модель пресета, стабильна на 170 KB); `security` (gpt-5.5) — 0 находок (валидный исход). Diversity: architect/judge = opus, correctness/security = gpt-5.5, tests/performance/maintainability = deepseek — 3 вендора, judge ≠ модель большинства.
- maintainability-роль: 0 находок.

---
_Артефакты: `.review/consensus-w2.json` (агрегация), `.review/raw/w2_judge.json` (вердикт судьи), `.review/raw/w2_*.json` (сырые ответы ролей), `.review/packet-w2t05.md` (что видели ревьюеры)._

---

# Fixloop W2 — верификация фикса MF-1 (раунд 2)

> **Режим:** review-fixloop · **Дата:** 2026-07-12 · **Вход:** MF-1 (verbatim) + дифф `bf0e0f7..0c7850f` (коммиты 40305b9, 0c7850f) · сьют 675 passed (+2 repro-теста)
> **Состав (сокращённый):** correctness = `anthropic/claude-opus-4.8`, architect = `openai/gpt-5.5` (разные вендоры для перекрёстной проверки спорного FREE-решения); judge = `claude-opus-4.8`. Redaction-скан диффа — чисто.
> **Стоимость раунда: $0.18** (OpenRouter, 314.13 → 314.31). Нарастающий итог по блоку за день: **$17.73**. Фолбэк-режим: не применялся.
> Артефакты: `.review/packet-w2fix.md`, `.review/raw/w2f_*.json`, `.review/consensus-w2f.json`.

## Вердикт раунда: `request_changes` — фикс НЕ принят, нужна ещё одна правка

MF-1 в исходной формулировке (лот застревает в `grace_held` навсегда) **закрыт**, но фикс вносит **инвертированный дефект** того же класса: пост-дедлайновый лот только что просроченного владельца оставлен в `STATE_FREE`, а матчинг T03 потребляет FREE-лоты БЕЗ фильтра по состоянию владельца → PV засчитывается участнику `grace_expired`, который так и не заработал реферала (нарушение BR-REG-004 «объёмы считаются только после появления реферала»). **Два ревьюера разных вендоров (Opus correctness + GPT-5.5 architect) независимо сошлись на одном корне** с конкретным сценарием — escalation-floor (money-adjacent) → request_changes.

## Ответы на два вопроса

**Q1 — закрыт ли MF-1 без новых дыр?** Частично. Застревание в `grace_held` устранено корректно:
- `holdIncomingLotsForGraceClients($orderId, $paidAt)` сперва прогоняет владельцев с `grace_expires_at < paidAt` через `expireGrace()`, затем в `grace_held` кладёт только активный grace (`grace_expires_at >= paidAt`) — граница `==paidAt` трактуется как «ещё активен» (held), гэпа на равенстве нет.
- `GracePvLotAnnulmentService` теперь аннулирует ВСЕ `grace_held` (плюс FREE с `occurred_at<=until`) → инвариант «grace_expired ⇒ ноль grace_held» держится; over-annul легитимного PV нет (grace_held по определению существует только пока grace не решён).
- `expireGrace`, вызываемый теперь и из hold-пути под advisory-lock: идемпотентен и re-entry-safe (verified: `lockForUpdate` + early-return при `state != client || grace_outcome != null || grace_expires_at future`; hold-запрос уже фильтрует `state=client AND grace_outcome IS NULL`). Судья дропнул low-находку об этом как unsupported.

НО остаётся **новая дыра (см. Q2)** — значит MF-1 в целом ещё не acceptable.

**Q2 — корректно ли FREE-решение по спеке?** **Нет, не полностью.** PV действительно НЕ теряется (лот жив, `pv_available` цел) — в этом агент прав. Но FREE создаёт противоположный баг: пока владелец `grace_expired` (реферала не было, не CONSULTANT), его FREE-лот уже потребляем матчингом (`BinaryMatchingService::runMatching` берёт `owner_member_id=member AND state=FREE`, без owner-state-гейта — верифицировано оркестратором по коду). Изоляция grace держится ИСКЛЮЧИТЕЛЬНО на том, что лоты неактивированного клиента НЕ в состоянии FREE. Аргумент «occurred_at после дедлайна ⇒ не grace-период ⇒ не аннулируем» верен для аннулирования, но не оправдывает FREE: спека BR-REG-004 требует не считать объёмы до реферала, а FREE их считает.

## Незакрытое (must-fix для следующего раунда)

- **[MF-1b, high, подтверждено 2 вендора]** `ClientLifecycleService.php:115-143` — пост-дедлайновые лоты `grace_expired`-владельца не оставлять FREE. Варианты фикса (совет): (а) держать их в нематчабельном состоянии (grace_held / отдельный held) после `expireGrace()`, освобождать в FREE только на `succeedGrace` (когда владелец станет CONSULTANT); либо (б) добавить owner-state-фильтр в матчинг T03 — не потреблять FREE-лоты владельцев в состоянии `client`/`grace_expired`. Вариант (а) локальнее и не трогает T03. Добавить repro-тест: `grace_expired`-владелец с FREE-лотом на обеих ветках → прогон матчинга не засчитывает его PV, пока не CONSULTANT.

## Итог

`request_changes` (W2 интеграционное) → фикс MF-1 → **всё ещё `request_changes`** (инвертированный дефект MF-1b). Раунд 2 fixloop НЕ достиг acceptable: grace_held-застревание закрыто, но нужна правка неактивированного FREE. Следующий фикс + короткий re-verify того же класса (матчабельность лота неактивированного владельца).

---

# Fixloop W2 — раунд 2: верификация MF-1b (финал)

> **Режим:** review-fixloop · **Дата:** 2026-07-12 · **Вход:** MF-1b (verbatim) + дифф `0c7850f..d89c32c` (коммит d89c32c) + код двух необгейченных read-моделей (для Q2) · сьют 676 passed (+repro `testGraceExpiredOwnerLotsNotMatchableUntilConsultant`, red→green)
> **Состав (сокращённый):** correctness = `anthropic/claude-opus-4.8`, architect = `openai/gpt-5.5` (разные вендоры); judge = `claude-opus-4.8`. Redaction-скан диффа — чисто.
> **Стоимость раунда: $0.18** (OpenRouter, 314.31 → 314.49). Нарастающий итог по блоку за день: **$17.91**. Фолбэк-режим: не применялся.
> Артефакты: `.review/packet-w2fix2.md`, `.review/raw/w2f2_*.json`, `.review/consensus-w2f2.json`.

## Вердикт раунда: `approve_with_notes` — MF-1b ЗАКРЫТ, волна W2 (T05) ACCEPTABLE

Ни один дефект, ВНЕСЁННЫЙ этим диффом, не пережил ревью. Owner-state-гейт в `BinaryMatchingService::runMatching` корректно изолирует лоты владельца в состоянии `{client, grace_expired}`; при активации (`succeedGrace` ИЛИ `grace_expired→CONSULTANT`) те же FREE-лоты матчатся следующим прогоном — PV откладывается, не теряется. Зеркальной дыры прошлого раунда нет.

## Q1 — закрыт ли MF-1b без нового инвертированного дефекта? Да (confirmed_fixed)

Проверено (оркестратор по коду + оба ревьюера):
- **Восстановление отложенного PV безопасно:** `runMatching` фильтрует `occurred_at < cutoff` (не привязан к периоду), carryover бессрочный (DEC-018) → более поздний прогон с бóльшим cutoff перечитывает всё ещё FREE-лоты. Пока владелец загейчен — 0 аллокаций, `pv_available` нетронут → двойного счёта на последующем матче нет. Тест red→green подтверждает (m1 matched=0 в grace_expired, m2 matched=100 после CONSULTANT).
- **Переход grace_expired→CONSULTANT:** нового застревания/утечки/двойного счёта нет.
- **Обратная совместимость:** `consultant+` и отсутствие строки статуса (флаг OFF / легаси) — матчабельны как раньше; поведение `none` НЕ изменилось этим фиксом (до фикса гейта не было вовсе).

## Q2 (КЛЮЧЕВОЕ допущение) — необгейченный BranchStats: течёт или нет? **Не течёт по денежному пути** (допущение автора подтверждено)

Оркестратор прочитал `PvLotBinaryVolumeReader` и `BranchStatsService` и разобрал все векторы:
- **(a) small_branch_pv вышестоящего X включает PV поддерева grace_expired-Y** — `leftLifetimePv(X) = Σ pv_original − Σ pv_reversed` по лотам `owner=X`. Эти лоты порождены РЕАЛЬНЫМИ покупками даунлайна Y (C/D); аннулированный grace-период PV уже вычтен через `pv_reversed`. Бинарный ранг объёмный: статус промежуточного узла Y не отменяет факта покупок C/D. **НЕ утечка** — X зарабатывает порог реальным объёмом продаж своей бинар-организации. (Формулировка автора «ветвь самого получателя» неточна, но вывод верен.)
- **(b) qualifiedL1Referrals засчитывает просроченного grace_expired-клиента** (у него `client_achieved_at` заполнен) в требование спонсора MANAGER(4)/BRONZE(8) — реальная **спека-неоднозначность** (DEC-021 «активированный партнёр 100 PV» + «ранг навсегда»), НО: pre-existing (этим фиксом не внесено), не денежная выплата, отдельный механизм. → **вопрос владельцу**, не must-fix.
- **(c) grace_expired/CLIENT кандидат для чужих variant-слотов** — макс. ранг CLIENT ordinal 0 < Silver anchor/support → слот закрыть не может. **Безопасно.**

Вывод по Q2: необгейченный `BranchStatsService` не даёт grace_expired-участнику или вышестоящему НЕЗАРАБОТАННОГО денежного порога; допущение автора держится. Единственное, что стоит вынести владельцу — семантика (b) (засчитывать ли лапс-клиента как квалифицированного реферала), но это не блокер MF-1b и не дефект кода.

## Notes (не блокируют; hardening/follow-up)

1. **[architect high/med → judge low, pre-existing]** `ownerMatchable` — deny-list `{client, grace_expired}`; явный `none`/NULL-владелец остаётся матчабельным. НЕ внесено этим фиксом (до фикса гейта не было) и downstream T06 гейтит структурную выплату по рангу (у `none`/безрангового матч платит 0). Рекомендация hardening: перейти на **allow-list** — матчабелен `consultant+` ЛИБО отсутствие строки (легаси/флаг-OFF), а явный `none`-row требует активации. Defense-in-depth, не блокер.
2. **[correctness low]** Кросс-периодное восстановление отложенного PV не покрыто тестом (механизм верно работает по коду). Добавить тест: grace_expired в периоде N с FREE-лотами → активация в N+1 → лоты периода N матчатся в прогоне N+1.
3. **[Q2b, владельцу]** Решение: считается ли просроченный grace_expired-клиент квалифицированным L1-рефералом спонсора? Сейчас — да (`client_achieved_at` заполнен). Вынести в T05/owner-ruling.

## Итог волны W2 (T05)

`request_changes` (интеграционное) → MF-1 → фикс с зеркальной дырой MF-1b (`request_changes`) → фикс MF-1b → **`approve_with_notes` (ACCEPTABLE)**. T05 допущена; notes 1–3 — follow-up (hardening allow-list, кросс-период тест, owner-ruling по лапс-рефералу), не блокируют приёмку/включение флага. Денежных/authz-дыр, внесённых кодом T05, не осталось.
