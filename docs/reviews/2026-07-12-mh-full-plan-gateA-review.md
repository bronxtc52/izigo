# Внешнее ревью советом моделей — Гейт A блока mh-full-plan (пре-фильтр /armada)

> **Режим:** review-plan (multi-llm-reviewer) · **Дата:** 2026-07-12
> **Объект:** `docs/specs/2026-07-12-mh-full-plan-gateA-plans.md` (16 задач T01–T16) + контекст `2026-07-12-mh-full-plan-dec-triage.md` + `2026-07-12-mh-full-plan-roadmap.yml`
> **Совет:** architect = `anthropic/claude-opus-4.8` · security / correctness / product_risk = `openai/gpt-5.5` · performance = `deepseek/deepseek-v4-pro` · **Judge:** `anthropic/claude-opus-4.8`
> Кворум 5/5 ролей, DEGRADED: нет. Фолбэк-режим (бесплатная деградация): **не применялся** — прогон платный, полный.
> **Стоимость прогона:** **$5.47** (OpenRouter, дельта total_usage 296.58 → 302.05; включает 2 неудачные попытки sonnet-5 и ретрай architect на Opus).
> Артефакты: `.review/consensus.final.json`, `.review/findings.json`, `.review/packet.md` (348 KB: планы целиком без дублирующей «Сводки вопросов», триаж DEC полностью, роадмап полностью).

## Вердикт: `request_changes`

Резюме судьи: план-ревью денежно-критичного блока из 16 задач; несущие риски — рассинхрон контрактов между параллельными задачами и математика 60%-пула. Секретов нет, подтверждённого certain-critical нет, но несколько high-находок в домене денег/authz с конкретными доказательствами пережили фильтр → request_changes. Все находки формально single-reviewer (по одному специалисту на полосу — структурная особенность плана-ревью), но 6 из 11 must-fix подтверждены 2–3 ревьюерами после семантического слияния судьёй.

## Must-fix (11) — закрыть ДО запуска волн кодинга

### Critical

1. **[architect-1] 60%-пул ↔ лидерский: неподвижная точка не решена, T08/T11 кодируют противоположные допущения** — `critical/high`, подтверждено architect + correctness. T11 не зависит от T08; T08 предполагает «провизорный лидерский включается в числитель, итераций не требуется», T11 спрашивает обратное («лидерский НЕ входит в числитель, иначе цикл»), DEC-014 говорит «включены все бонусы». Если лидерский в базе, Σ сама содержит член, пропорциональный f — однопроходная формула f = min(1, 0.6·BV/Σ) неверна. **Фикс:** на Гейте A заморозить точное определение числителя и решить fixed point в замкнутой форме (если лидерский в базе: f = min(1, 0.6·BV/(S + L_provisional))); формула живёт только в T11 PoolFactor, T08 читает закоммиченный фактор и сам ничего не считает.
2. **[correctness-1] Формула фактора 60%-пула в тексте T11 как написана даёт всегда 1** — `critical/high`. «f = min(1, rate_bps*base_bv / 10000*Σ)» из-за приоритета операций = ((6000·10000)/10000)·10000 → min(1,…)=1: месяцы сверх капа НЕ будут скейлиться, кап владельца молча не работает. **Фикс:** явные скобки и целочисленный порядок: `pool_cap_cents = floor(base_bv_cents*rate_bps/10000); factor = min(1, pool_cap_cents / total_after_caps_cents)`.

### High

3. **[correctness-4] Квалификационные награды (T10) vs 60%-база не определены** — `high/high`, подтверждено correctness + product_risk. DEC-014 «все бонусы», T10/T11 предлагают исключить; единоразовая награда (Pearl 20 000 USD при месячном BV 1 000 USD) рвёт инвариант капа на порядки. **Фикс:** заморозить included_bonus_kinds на Гейте A; исключение наград оформить как явное owner-approved исключение из DEC-014, иначе T10 → зависимость T11.
4. **[architect-2] Перевод НС→ОС 1/16 vs месячная 60%-калибровка = необратимая переплата / clawback по уже выведенным деньгам** — `high/medium`. H1-структурная уходит на ОС 16-го, а f известен только на month-close; партнёр успевает вывести — корректирующий дебет упирается в clawback_debt по реальным ушедшим деньгам (стык T02/T04/T06/T11, OPEN-POOL-02). **Фикс (рекомендация совета):** вариант A — держать H1-структурную в НС до закрытия месяца и коммита калибровки, переводить после f (меняет семантику «1/16» в T06); альтернатива B — провизорный консервативный фактор с true-up.
5. **[architect-3] Контракт PolicyVersionResolver разъехался по T01/T02/T03/T04** — `high/high`. Три имени метода и три типа возврата (resolveForDate→PolicyV2 / configFor→array / resolveForDate→int / forDate→PolicyVersion) у одного T01-владельца. **Фикс:** заморозить единственную сигнатуру на Гейте A и опубликовать до мерджей волны 1.
6. **[correctness-5] Два класса NsToOsTransferCommand по одному пути** — `high/high`, подтверждено correctness + architect + product_risk (3 ревьюера). T02 (`mh2:ns-transfer`, cron 1/16 безусловно) и T04 (`calc-v2:ns-os-transfer`, daily, гейт по закрытому half-month) — коллизия файла и двойной/несогласованный по времени перенос денег. **Фикс:** T02 владеет только ОПЕРАЦИЕЙ (handler), T04 — единственной командой и расписанием с гейтом по закрытию; команду и schedule-строку из T02 убрать.
7. **[correctness-6] Grace CLIENT: сканер ищет state=client_grace, сервис пишет state=client** — `high/high`. Просроченный grace никогда не находится сканером → PV-лоты не аннулируются, state не становится grace_expired, гниёт квалификация и бонусы ниже по цепочке. **Фикс:** один канонический in-grace state в T05.
8. **[correctness-7] T13-админка ссылается на таблицы/статусы, которых задачи-владельцы не создают** — `high/high`, подтверждено correctness + product_risk. `v2_policy_versions` со статусом APPROVED (у T01 его нет), `v2_wallet_lots` (реально `wallet_lots_v2`), `v2_calc_periods` (реально `calc_periods_v2`), `v2_rank_reward_entitlements` pending/paid/held (у T10 — `v2_award_entitlements` granted/on_hold/paid_out/forfeited), `v2_period_calibrations` (у T11 — `v2_pool_calibrations`). Ломаются основные админ-потоки, включая денежную ручную выплату наград. **Фикс:** единый словарь схемы; T13 потребляет read/action-интерфейсы задач-владельцев, а не свои имена.
9. **[product_risk-3] БС-лоты наград авто-сгорают через год ожидания ручной выплаты** — `high/high`, подтверждено product_risk + architect. T02 credit() всегда ставит expires_at, expireLots форфейтит БС; крупная награда (VP $150k) молча сгорает — денежная потеря пользователя. **Фикс:** nullable expiry в T02 credit(), expireLots пропускает `expires_at IS NULL`, T10 кредитует награды с null; подтвердить у владельца «награды не сгорают».
10. **[product_risk-6] Нет rollback-плейбука для заказов, обработанных при живом V2 (T15)** — `high/medium`, домен data-migration (escalation floor). Rollback = «выключить флаг», но V2-проводки не удаляются, а V1 recompute() для V2-периода не вызывался → нерасхождённые балансы. **Фикс:** ограничить простой rollback окном «без новых заказов» либо компенсирующие V2-reversals + V1-backfill под тем же advisory-lock.
11. **[product_risk-8] Публичная политика T16 обещает «компрессию минус два», движок T08/DEC-030 — блок БЕЗ компрессии** — `high/high`. Внешнее партнёрское обязательство разойдётся с выплатами → диспуты. **Фикс:** привести текст T16 к точной семантике DEC-030.

## Nice-to-have (8)

- **[security-1]** `high/medium` — роль-middleware на V2-админ-роуты денег/конфига (activate policy, mark-paid): required-роль в route middleware (`calculator.role:owner`; чтение — owner,finance), а не в комментариях/фронте; разделить read/mutation-группы.
- **[security-2]** `medium` — IDOR-скоуп на cabinet account-payment endpoints: заказ резолвить через аутентифицированного Telegram-члена, negative-тесты на чужой order id.
- **[architect-4]** `medium/high` (подтверждено 2) — точность PV: decimal(18,6) в лотах vs 12,2 в partner_states; единые decimal(18,6) везде; `matched_bv_cents` из T03 — единственный денежный вход T06 (T06 не пере-выводит BV).
- **[architect-9]** `medium/high` — два конкурирующих конвейера пост-оплаты: принять PaidOrderV2Pipeline (T03) как единственную точку расширения; T07 регистрирует ReferralBonusStep, а не правит markPaid напрямую.
- **[architect-7]** `medium/medium` — единый ACTIVATION_LOCK_KEY у activation/close/rank/refund/cutover: определить дисциплину на Гейте A (pg_advisory_xact_lock берёт только внешний оркестратор, внутренние сервисы assert-lock-held).
- **[architect-6]** `medium/high` — T12 условной миграцией ALTER'ит v2_pv_lots задачи T03: колонку reversal_of_lot_id закладывает T03 сразу; T12 только использует PvLotReversalService.
- **[product_risk-7]** `medium/high` — лазейка 100% оплаты со счетов (заказ без внешних денег): явно решить BV-эligibility внутренне-финансируемых заказов (запретить / ограничить долю / не-комиссионный BV).
- **[correctness-8]** `medium/high` — UNALLOCATED-строки глобального пула с nullable member_id не дедупятся unique(pool_id, member_id): sentinel-получатель или partial unique index.

## Disagreements

Нет (0). Расхождений severity ≥ high между ревьюерами не зафиксировано, tiebreak не запускался.

## Dropped судьёй (12)

7 дублей слиты в консенсус-кластеры (учтены как подтверждения: correctness-2/3, product_risk-2/4/5, architect-5/8, correctness-9); **[product_risk-1]** (referral stop-at-ELITE) — снят как «открытый вопрос Гейта A, а не дефект» (ответ принадлежит листу решений владельца, дефолт кода TRUE указан в плане); performance-1/2/3 — speculative на масштабе прода в 5 участников (concurrency-суть сохранена в architect-7).

## Прогон / честность процесса

- Packet: планы T01–T16 целиком + карта рисков; вырезана только «Сводка вопросов к владельцу» (точный дубль пер-задачных «Вопросов к Гейту A»); триаж DEC-001..057 и roadmap.yml — целиком. Секрет-скан packet — чисто.
- Сбои и деградации по ходу: `anthropic/claude-sonnet-5` дважды не ответил валидно (architect — empty content, product_risk — non-JSON), `google/gemini-3.1-pro-preview` дважды non-JSON на architect; product_risk доехал на fallback `openai/gpt-5.5`, architect — ретраем на `anthropic/claude-opus-4.8`. Итог — полный совет 5/5, кворум соблюдён, судья — Opus (≠ модель большинства).
- Допущения оркестратора: (1) к product_risk добавлен fallback gpt-5.5 сверх пресета balanced — ради устойчивости роли; (2) architect ретраен на opus-4.8 (пресет strong) после падения обоих balanced-моделей; (3) MLR_MAX_TOKENS поднят 6000→8000/16000 под большой packet.

## Рекомендация для Гейта A

План добротный по глубине (ledger-инварианты, идемпотентность, тест-планы с negative/concurrency в каждой задаче), но **вход в волны кодинга — только после**: (а) закрытия 2 critical по 60%-пулу (состав базы + формула + порядок с лидерским/наградами/НС→ОС — это один связанный пакет решений владельца: DEC-014/029/053 + OPEN-POOL-02), (б) заморозки межзадачных контрактов первым коммитом блока (PolicyVersionResolver, владелец NS→OS команды, словарь таблиц/статусов для T13, nullable expiry в T02 credit()), (в) правки текстовых багов планов (grace-state T05, формула T11, семантика T16).

---

# Fixloop — раунд 2 (верификация поправок)

> **Режим:** review-fixloop · **Дата:** 2026-07-12
> **Вход:** fixlist раунда 1 (11 must-fix + 8 nice-to-have с evidence) + `docs/specs/2026-07-12-mh-full-plan-gateA-amendments.md` (полные планы повторно не отправлялись — verification pass по выдержкам).
> **Состав (сокращённый, по зонам открытых находок):** correctness = `anthropic/claude-opus-4.8`, security = `openai/gpt-5.5`; chair — оркестратор (Claude Code) по judge.md — оба внешних ревьюера ответили, отдельный внешний судья на 2-ролевом верифай-пассе не гонялся (экономия, семантика слияния тривиальна).
> **Стоимость раунда 2:** **$0.20** (OpenRouter, 302.05 → 302.25). **Итого оба раунда: $5.67.** Фолбэк-режим: не применялся.
> Артефакты: `.review/packet-fixloop.md`, `.review/raw/r2_*.json`, `.review/consensus-r2.json`, `.review/history/consensus-round1.json`.

## Вердикт раунда: `approve_with_notes` — план ACCEPTABLE, волны кодинга можно запускать

## Статусы находок

**Must-fix — 11/11 confirmed_fixed:**

| # | Находка р.1 | Поправка | Статус | Ключевое подтверждение совета |
|---|---|---|---|---|
| 1 | architect-1 (60%-пул ↔ лидерский fixed point) | MF-1/2 | ✅ confirmed_fixed | лидерский исключён из числителя → цикла нет, T08 читает закоммиченный factor_bps и не считает сам |
| 2 | correctness-1 (формула фактора = 1) | MF-1/2 | ✅ confirmed_fixed | перепроверка арифметикой: base_bv=10000, Σ=10000 → factor_bps=6000 (0.6); zero-denominator защищён; largest-remainder + Σ≤cap корректны |
| 3 | correctness-4 (награды vs 60%-база) | MF-1/2 | ✅ confirmed_fixed | исключение оформлено как явное owner-approved исключение из DEC-014; награды идут мимо пула через v2_award_entitlements |
| 4 | architect-2 (НС→ОС 1/16 vs калибровка, clawback) | MF-4 | ✅ confirmed_fixed | вариант A: деньги достигают ОС только ПОСЛЕ коммита factor_bps → clawback-путь калибровки устранён полностью; clawback остаётся только у возвратов T12 |
| 5 | architect-3 (контракт PolicyVersionResolver) | MF-5 | ✅ confirmed_fixed | одна сигнатура forDate(DateTimeInterface): PolicyV2; выбор DateTimeInterface вместо CarbonInterface — валидный (Carbon его расширяет) |
| 6 | correctness-5 (два NsToOsTransferCommand) | MF-6 | ✅ confirmed_fixed | команда/расписание только у T04; коллизия файла устранена |
| 7 | correctness-6 (grace-state T05) | MF-7 | ✅ confirmed_fixed | writer и scanner сходятся: state='client' + grace_expires_at |
| 8 | correctness-7 (словарь таблиц T13) | MF-8 | ✅ confirmed_fixed | канонические имена v2_* + статусы зафиксированы, T13 не вводит своих |
| 9 | product_risk-3 (сгорание award-лотов) | MF-9 | ✅ confirmed_fixed | nullable expiry в credit(), expireLots пропускает NULL |
| 10 | product_risk-6 (rollback cutover) | MF-10 | ✅ confirmed_fixed (chair, textual) | поправка дословно реализует required-fix р.1: окно «без новых заказов» / компенсирующие reversals + V1-backfill под ACTIVATION_LOCK + staging-репетиция |
| 11 | product_risk-8 (T16 «компрессия») | MF-11 | ✅ confirmed_fixed (chair, textual) | текст политики переведён на точную семантику DEC-030 «блок без передачи» |

**Nice-to-have — 8/8 приняты:** security-1/2, architect-4/9/7, product_risk-7, correctness-8 — дословно в разделе «Принятые nice-to-have» поправок; architect-6 закрыт внутри MF-8 (`reversal_of_lot_id` закладывает T03). product_risk-7 закрыт как явное решение: внутренне-финансируемые заказы разрешены с полноценным BV + конфиг-рычаг `internal_funding_full_bv`.

**Новые дыры от самих поправок:** совет не нашёл (security-ревьюер — 0 находок; корректность — одна заметка ниже).

## Notes (не блокируют, взять в работу)

1. **[r2 correctness-5, medium/medium] Резидуал MF-4 — два новых user-visible контракта требуют явного sign-off владельца:** (а) лаг доступности H1-структурной вырос до ~2–6 недель (начислено ~15-го, на ОС — 1-го числа следующего месяца) против прежнего «1/16»; (б) сматченный PV, не оплаченный из-за капа/калибровки, теперь сгорает безвозвратно (каскад T06). Оба уже помечены в поправках как решения владельца — зафиксировать подпись в листе решений Гейта A. Технически: добавить в T11 worked example и **ledger-sink счёт для дельты (raw − paid)**, чтобы двойная запись сходилась на калибровке.
2. **Coverage-оговорка (честность прогона):** пункты 10–11 таблицы и nice-to-have подтверждены chair'ом текстуальной сверкой (поправки дословно повторяют required-fix раунда 1), а не внешней моделью — correctness-ревьюер упёрся в лимит 10 находок на вход и покрыл MF-1..MF-9 + correctness-8. Расхождений при сверке нет.

## Итог Гейта A

`request_changes` (р.1) → поправки → `approve_with_notes` (р.2). Блок допущен к волнам кодинга при условии: amendments-документ обязателен для всех кодинг-агентов (приоритет над текстами планов), note-1 (sign-off владельца + ledger-sink) внести в T11/T06 до старта их волны.
