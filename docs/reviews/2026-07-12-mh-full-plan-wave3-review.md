# Ревью волны W3 (mh-full-plan) — совет моделей, режим review-diff

**Дата:** 2026-07-12 · **Диапазон:** `75a8a76..43d9cda` (baseline W2 → tip W3)
**Задачи:** T06 структурная премия · T07 реферальная · T09 глобальный бонус · T10 квал-награды
**Совет (strong-preset, OpenRouter):** correctness (opus-4.8), security (gpt-5.5), architect (opus-4.8), product-risk (opus-4.8). Судья — оркестратор (Claude Code). Кворум ≥3 выполнен.
**Пакет:** только прод-код волны (4269 строк дифа), тесты исключены; секретов/PII нет.

## Вердикт: REQUEST_CHANGES

Движки денежно корректны в изоляции (целочисленная математика, двойная запись, идемпотентность, deny-by-default подтверждены), **но один заявленный внутриволновой стык T09→T10 не соединён в проде** — это дефект интеграции, замаскированный зелёным сьютом (метод хука вызывают только юнит-тесты T10). Плюс один кросс-волновой денежный риск (реферальная премия vs 60%-калибровка) требует явного решения до T11/go-live.

---

## MUST-FIX

### MF-W3-1 (high, correctness+architect, 2 подтверждения) — хук T09→T10 никогда не вызывается в проде
`GlobalBonusMonthlyService` создаёт `GlobalBonusQualification(shares≥1)` для VP, но НИ `allocateForMonth`, НИ `finalizeMonth`, НИ `GlobalBonusQuarterlyPayoutService` не вызывают `GlobalQualificationAwardHook::onGlobalQualificationCompleted()` — хук лишь связан в DI и вызывается только из `AwardsGrantTest`. Контракт (amendments) обязывает T09 звать его ровно 1 раз на (member, месяц). Следствие: **транши VP этапов 2-3 не начисляются никогда**. При починке: инъекция хука в T09 + передавать `monthKey` строго `YYYY-MM` (сейчас `onGlobalQualificationCompleted` сравнивает `trigger_ref === monthKey`, а month-код периода в других местах шире — см. MF-W3-2).

### MF-W3-2 (medium→свернут в MF-1) — формат monthKey хука
При вызове хука T09 обязан передать `YYYY-MM`, а не полный `code` периода (`substr(code,0,7)`), иначе идемпотентная сверка месяца в T10 (`trigger_ref === monthKey`) разъедется и один и тот же месяц породит лишний транш. Чинить вместе с MF-1.

### MF-W3-3 (high, product-risk+architect, 2 подтверждения) — реферальная премия постится gross на ОС без clawback
`ReferralBonusService` кредитует `gross_cents` на ОС немедленно при оплате (`net_cents=null`), но по amendments MF-1/2 реферальная входит в 60%-числитель калибровки и должна масштабироваться на `factor_bps` (T11); MF-4 запрещает clawback (кроме T12). Механизма скорректировать уже выплаченный на ОС gross нет → **риск переплаты при factor_bps<10000**. Задокументировано как «риск-карта Гейта A» в миграции T07, но остаётся нерешённым. T11 вне волны, движок за флагом OFF — живых денег пока нет; **решить архитектурно до включения T11** (варианты: реферальную тоже держать на НС до калибровки, как структурную; либо owner-решение «реферальная не калибруется 60%-пулом» с правкой числителя MF-1/2). Требует явного решения владельца.

---

## NICE-TO-HAVE (не блокируют мердж волны)

- **Квартальная выплата помечает PAID все member-строки**, включая тех, чья Σ по кварталу = 0 (proben `continue` в цикле, но `update(status=PAID)` — по всем accrued без условия проводки). Денег не теряет, но стирает различие «выплачено» / «нечего платить». (`GlobalBonusQuarterlyPayoutService:112-120`)
- **T10 payout обходит контракт `LedgerV2`**, собирая проводку БС→payouts_paid из low-level `LedgerPostingV2Service`+`WalletAccountsV2Service` с ручной мутацией лота/счёта. Задокументированный deviation (T02 не дал «выплату с БС»); адресное списание award-лота корректно, двойная запись сходится — но абстракция протекает. (`QualificationAwardService.markPaid`)
- **`AwardsStep.uplineIncluding()`** заново обходит sponsor-аплайн сырым SQL на каждого предка, дублируя владение деревом T05 в обход `StatusReader`. Для текущего масштаба ок. (`AwardsStep:56-70`)
- **`grantOne` ставит `posted_at` при insert entitlement** строкой раньше фактической ledger-проводки — семантически `posted_at` ≠ момент проводки. Косметика. (`QualificationAwardService:240-278`)

## РАССМОТРЕНО И ОТКЛОНЕНО (false positives / verified-ok)

- **«Двойное масштабирование делителя долей» (correctness high/medium)** — ОТКЛОНЕНО. Сверено с `DefaultPolicyConfig`: `one_share_pv_min` хранится в ЦЕЛЫХ PV (DIRECTOR=100000 PV), `treePvMicro` даёт PV×1e6, код делит на `base*1e6` — единицы согласованы, `base_pv`-снапшот («100000.000000») консистентен. Не баг (магнитуда порога — owner-конфиг).
- **CSRF на award-мутациях (security medium)** — ОТКЛОНЕНО. `WebAdminAuth` использует Bearer Sanctum-токен из заголовка Authorization (не cookie-сессия) → cross-site POST токен не приложит. CSRF неприменим.
- **Несогласованность флагов структурной (product-risk low)** — денежный путь T06 (calc/post-шаги) гейтится `mh_plan_v2_engine` в `supports()` (deny-by-default подтверждён; шаг ВЫРЕЗАЕТСЯ из пайплайна при OFF). UI-флаги `mh_plan_v2_miniapp/admin` — только на read-роутах. Приемлемо.
- **Allocate/Finalize-шаги не гейтят флаг в `supports()`** — гейтят в `execute()` (`skipped=flag_off`), денег не постят при OFF. Защитный нит, без money-impact.

## Что подтверждено корректным (денежное ядро)

- Целочисленная математика везде (`intdiv`, центы/bps, floor DEC-002); float отсутствует.
- Двойная запись через `LedgerV2::credit` (DR commission_expense / CR member_*), идемпотентно под локом счёта; НС — только `credit()` с валидируемым `accrualMonth`, БС/ОС — кредит-лоты.
- Награды не сгорают (`expiresAt=null`), payout БС симметричен гранту (нетит member_bs_available в ноль).
- Глобальный пул: `Σ capped(member)+unallocated==pool_amount`, кап 25% floor, excess→UNALLOCATED (компания, без ledger-проводки), largest-remainder точный, partial-unique на UNALLOCATED-строку.
- Идемпотентность: unique-ключи (period+member / order+depth / member+code+stage / quarter+member) + ledger idempotency_key; повтор = no-op (`insertOrIgnore` ON CONFLICT без aborted-tx).
- RBAC/IDOR: mutation-роуты owner-only, read owner/finance, cabinet — member из auth (id клиента не принимается); контроллеры читают auth-атрибут, не запрос.
- Структурная: сгорание сверх капа (`forfeited=gross-after_cap`), НС с accrual_month; matched_bv из T03 (T06 не пере-выводит BV). Awards НЕ входят в 60%-числитель (живут в отдельной таблице БС). ✔

## Стоимость

Раннер `run_openrouter_review.sh` не эмитит usage/cost. Оценка: пакет ~55K вход-токенов × 4 успешные роли (3× opus-4.8, 1× gpt-5.5) + 2 таймаут-попытки gpt-5.5-pro (частичный/неопределённый биллинг). **Оценочно ≈ $1.5–3.5** (точную сумму провайдер в ответе не вернул). Платный совет, standing consent — карточка не запрашивалась.
