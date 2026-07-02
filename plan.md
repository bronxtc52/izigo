# Активный план: Закрытие P1 продакшн-ревью (production hardening)

**ТЗ:** `docs/specs/2026-07-02-p1-production-hardening.md` (Гейт 1 утверждён 2026-07-02)
**Отчёт ревью (источник находок):** `docs/reviews/2026-07-02-production-review.md`
**Дата плана:** 2026-07-02 (Гейт 2)
**Конвейер:** `/armanda`
**Форма сдачи:** 3 тематических PR; мердж = прод-деплой — только по финальному «ок» (порядок backend → ops → frontend).
**Разведка:** 4 параллельных read-only прохода по коду 2026-07-02; все находки ревью подтверждены, строки уточнены.

---

## Решения открытых вопросов Гейта 2

| Вопрос из ТЗ | Решение | Почему |
|---|---|---|
| B7: самопис CRC16 vs библиотека | **Самопис** `TonAddress::validate()` (~30 строк + юнит-тесты на известных векторах) | В composer нет ничего TON/крипто; зрелые PHP-порты (olifanton/*) тянут GMP и десятки классов ради одной функции. Алгоритм фиксирован: base64url → 36 байт = tag(1)+workchain(1)+hash(32)+CRC16-CCITT/XModem(2); testnet-бит = `tag & 0x80` |
| O1: `\|\| true` построчно | **Убрать у всех**: `config:cache`, `route:cache`, `migrate --force` и у 3 сидеров | Сидеры идемпотентны (ProductSeeder `updateOrCreate`, FeatureFlagSeeder `firstOrCreate` — не перетирает админские тумблеры, AgreementSeeder — early-return). Падение любой команды = битый билд/схема → лучше не переключать трафик (ACA оставит старую ревизию) |
| B4: как гейтвей сообщает «опрос упал» | Новый статус **`'error'`** в контракте `PaymentGateway::pollStatus` | Сейчас сеть/HTTP-ошибки схлопываются в `'pending'` (TonPayGateway:70-76) — отличить нечем; расширение строкового enum минимально-инвазивно (Fake-гейтвей и switch в поллере правятся точечно) |
| B6: детерминированный dedup-ключ | `broadcast:{sha1(segment_json + "\n" + body_raw)}:m{memberId}` + «допоставить» для застрявших | Ключ не зависит от id записи → повторный dispatch того же контента не дублирует доставленных. Трейд-офф: намеренный повтор идентичного текста тому же сегменту не доставится повторно (это и есть идемпотентность); форс — изменить текст |

## PR-1 — Backend (`feat/p1-hardening-backend`)

Все пути — `mh-calc-backend-main/Modules/Calculator/`.

### B1. Poison-платёж не валит конвейер — `Services/PaymentService.php:202-212`
- [x] try/catch (`\Throwable`) вокруг тела итерации `foreach ($pending as $payment)`: `Log::error` с payment id + Sentry `captureException`, `continue`. Разведение с B4: сетевые/API-ошибки идут путём `'error'` (не исключение → не Sentry-flood); catch ловит только неожиданное (битые данные)
- [x] Guard от системной аварии: если **все** опросы прогона завершились `'error'` → TTL-блок пропустить целиком (лог warning); иначе TTL выполняется всегда
- [x] Тест: два pending-платежа, у первого битый order (`markPaid` бросает) → второй подтверждён, команда не упала

### B2. Сериализация активаций — `Services/ActivationService.php:38-81`
- [x] В начале `DB::transaction` в `activate()` (до `insertOrIgnore`): `DB::statement('SELECT pg_advisory_xact_lock(?)', [self::ACTIVATION_LOCK_KEY])`, фиксированная константа класса (задокументировать: глобальная сериализация всех пересчётов сети)
- [x] Зафиксировано разведкой: `recompute()` вызывается внутри той же транзакции `activate()` (:67) и своей не открывает — лок покрывает delete/rewrite снапшота и ledger-дельты. В код — assert/комментарий, что recompute нельзя выносить из транзакции
- [x] Единый порядок локов (анти-дедлок): любая транзакция, которая в итоге зовёт `activate()`, берёт advisory-lock **до** первых ledger-записей → в autoship лок берётся в начале транзакции `processOne`, перед `charge` (см. B3)
- [x] Подтвердить `withoutOverlapping()` на `commerce:tonpay-poll` (два поллера параллельно не ходят); лок блокирующий — активации короткие, отдельный lock_timeout поллеру не закладываем (упрощение, задокументировать)
- [x] Тесты: (а) вспомогательный — вторым DB-коннектом взять лок, на основном `SET LOCAL lock_timeout` внутри транзакции (не загрязнять коннект) → `activate()` падает по таймауту; (б) **основной денежный** — две конкурентные активации (второй PHP-процесс через artisan-команду) → ledger/earnings не задвоены; если конкурентный прогон в CI нестабилен — зафиксировать ограничение в плане и оставить (а) + существующие идемпотентность/аккруалы

### B3. Autoship атомарен — `Services/AutoshipService.php:113-160`
- [x] `processOne`: advisory-lock активаций (см. B2) + charge (:140-142) + `orders->create` + `markPaid` (:151-153) + advance подписки (:155-158) — в **одну** `DB::transaction`; `InsufficientFundsException` → rollback, затем `advanceRetry` отдельной транзакцией (как сейчас)
- [x] Аудит `LedgerService::charge`: убедиться, что он на той же connection и не коммитит сам (вложенный `DB::transaction` в Laravel = savepoint — ок; ручной commit/иная connection — переделать вызов)
- [x] `runDue` (:105-107): try/catch (`\Throwable`) per-подписка — лог+Sentry, прогон продолжается (закрывает P2-7)
- [x] Тест: на **реальном** LedgerService подмена, роняющая `markPaid` после charge → в ledger нет записи списания (rollback); poison-подписка не мешает следующей

### B4. TTL не съедает оплаченное — `Services/PaymentService.php` + `Services/Payment/*`
- [x] Контракт `PaymentGateway::pollStatus`: новый возврат `'error'`; `TonPayGateway` (:70-76): exception и `!successful()` → `'error'`; `FakeTonPayGateway` — режим `failNext()`/`failFor($memo)` для тестов
- [x] Перечислить **всех** потребителей `pollStatus` (grep: pollPending, `checkForMember`/`checkForLead`, recheck) — каждому явная обработка `'error'` (для user-facing check — вести себя как `'pending'`); `'error'` **никогда** не пишется в `payments.status`
- [x] `pollPending`: TTL-экспирация по **белому списку** — только платежи, успешно опрошенные в этом прогоне с результатом «перевода нет» (`whereIn('id', $polledOkIds)` + старше TTL), а не blacklist errored (персистентный `last_poll_result` в БД — вне скоупа, не заводим)
- [x] Перед TTL-update: выбрать id кандидатов → `Log::warning` с полным списком; в Sentry — count + первые 10 id (не флудить)
- [x] Админ-ручка `POST /admin/payments/{id}/recheck` в `Routes/api.php` (admin-группа :135+): `middleware('calculator.role:owner,finance')`, метод в `CommerceAdminController` по паттерну `guarded()` + `audit->recordSafe(..., 'payment.recheck', 'payment', $id, ['old_status' => ..., 'result' => ...])`. Порядок: `pollStatus` **вне** DB-транзакции (не держать row-lock во время HTTP к tonapi) → при `'paid'` короткая транзакция: lock платежа → перепроверить статус ∈ {PENDING, EXPIRED} → `applyPaid`-путь напрямую (без промежуточного возврата в PENDING — меньше состояний; переход `{pending,expired}→paid` идемпотентен, активация внутри берёт advisory-lock B2)
- [x] Тесты: платёж старше TTL + гейтвей в error-режиме → НЕ экспирирован; recheck expired-платежа с деньгами (`FakeTonPayGateway::fakePay`) → PAID + активация; recheck конкурентно с тиком поллера → одна активация (идемпотентность по event-ключу)

### B5. Enforcement флагов c1..c7 — новый middleware
- [x] `Http/Middleware/EnsureFeatureFlag.php`: параметр-алиас, `FeatureFlagService::isEnabled($alias)` → иначе 403 `{message: 'Feature is not available.', code: 'FEATURE_DISABLED'}` (контракт как в AiAssistantController:28-31)
- [x] Алиас `feature.flag` в `Providers/CalculatorServiceProvider.php:registerMiddlewares()` (:53-63)
- [x] Повесить на группы в `Routes/api/`: `notifications.php`→`c1_notifications` (cabinet+admin), `helpdesk.php`→`c2_helpdesk`, `exports.php`→`c5_pii_export`, `copartners.php`→`c6_copartners`, `monitoring.php`→`c7_jobs_monitor`, `i18n.php`→`c4_i18n_admin` **только admin-группа**
- [x] Аудит покрытия: пройти все 7 роут-файлов + `api.php` — каждый роут Блока C либо в гейченной группе, либо в явном списке исключений с обоснованием (одиночный роут вне группы = дыра)
- [x] Исключения (by design): `feature_flags.php` (c3) — owner-only без флага; cabinet `GET /feature-flags/active` — без флага (фронт по нему узнаёт табы); публичный `GET /i18n/overrides` — без флага (c4 выключает админ-управление переводами, не runtime-serving) — **допущения, см. лог**; закрепить тестом, что эти роуты сознательно не-403 при выключенных флагах
- [x] Тест-страховка от осиротевшего алиаса: все алиасы из `feature.flag:*` в роутах существуют в `FeatureFlagSeeder` (опечатка в алиасе = перманентный 403 при deny-by-default)
- [x] Тест (параметризованный): по каждому флагу — выключен → 403 на характерный роут, включён → не-403; между кейсами сбрасывать кэш флагов (TTL 60с в `FeatureFlagService`)

### B6. Рассылка: bulk + идемпотентность — `Services/Notification/`
- [x] Канонизация контент-ключа: базовый dedup-ключ = `'broadcast:v1:' . sha1(canonical($segment) . "\n" . $bodyRaw)`, где `canonical` = рекурсивный `ksort` + `json_encode(..., JSON_UNESCAPED_UNICODE)`; включить в хэш все влияющие на доставку поля (title, если есть). `broadcast_id` в outbox остаётся FK для трассировки
- [x] `NotificationService::enqueueForMembers` (:51-131): двухфазный bulk в **одной** транзакции — (1) `insertOrIgnore` outbox чанками по 500; (2) select outbox id по этим dedup-ключам → bulk-достройка inbox (проверить схему inbox: если нет ключа идемпотентности — добавить nullable `dedup_key` + unique миграцией, иначе повтор задваивает inbox). Счётчик «поставлено» — по фактически вставленным строкам
- [x] Повтор застрявшей рассылки: ручка `resume` в `BroadcastAdminController` — `calculator.role:owner,support` (как send), audit-event, разрешена только из `processing`; повторный enqueue тем же контент-ключом допоставляет недостающих. `DONE` = «постановка завершена» (существующая семантика; доставку отслеживает outbox/мониторинг C7, не resume)
- [x] Тест: dispatch → эмуляция зависания (processing) → resume → у уже поставленных по одной записи outbox+inbox, недостающие достроены; тот же логический сегмент с другим порядком ключей → тот же dedup-ключ; новый контент → новые записи

### B7. Валидация TON-адреса — `Services/WithdrawalService.php:91-93`
- [x] Новый `Support/TonAddress.php`: `validate(string $addr): bool` — base64/base64url decode → длина 36, CRC16-CCITT (poly 0x1021, init 0x0000) первых 34 байт == последние 2 (**big-endian**); **testnet-бит (`tag & 0x80`) → невалиден**; после снятия testnet-бита tag ∈ {0x11 bounceable, 0x51 non-bounceable}
- [x] `WithdrawalService::create`: невалидный `payout_details` → `ValidationException::withMessages()` (честный 422; RuntimeException через `guarded()` дал бы 404)
- [x] **Общий файл тест-векторов** (JSON в репо: валидный EQ/UQ mainnet, битая CRC, testnet kQ/0Q, мусор/длина) — на него ссылаются и юнит-тесты B7 (PHP), и F3 (JS): самопис бэка и `@ton/core` фронта не должны расходиться

## PR-3 — Ops (`chore/p1-hardening-ops`) — мерджится вторым

### O1. `docker/start.sh` (backend)
- [x] Убрать `|| true` у всех 6 команд (см. таблицу решений); `set -e` уже стоит — упавшая миграция/сидер = упавший старт. Критерии сняты для каждого сидера: без внешних зависимостей, не перетирает прод-состояние (FeatureFlagSeeder — `firstOrCreate`), безопасен на повторе
- [ ] Риск-контроль (не обещание автоматики): при деплое PR-3 проверить поведение ACA-ревизии при падающем старте (revision mode/трафик) — цель «unhealthy ревизия не получает трафик» подтвердить наблюдением, single-replica downtime-риск осознан
- [x] Страховка `route:cache`: после роут-правок PR-1 (`feature.flag`, recheck) `php artisan route:cache` гоняется в CI-джобе (см. O2) — некешируемый роут ловится до прод-старта

### O2. Тесты в CI — `.github/workflows/deploy.yml`
- [x] **Job `test` добавляется уже в PR-1** (на `pull_request` + push main) — денежный PR не уезжает в прод без гейта; enforcement `needs: test` у деплой-jobs — в PR-3
- [x] `services: postgres:16` c healthcheck (`pg_isready`) и `ports: 5432:5432`; env: `DB_CONNECTION=pgsql, DB_HOST=127.0.0.1` (**не** `localhost`/имя сервиса — job на runner-хосте), `DB_DATABASE=izigo_test`; ltree в official-образе есть, миграция сама делает `CREATE EXTENSION` (сервисный юзер — суперюзер)
- [x] Bootstrap явно: setup-php 8.3 + extensions `pdo_pgsql,pgsql` → `composer install` → `cp .env.example .env` + `php artisan key:generate` → `php artisan config:cache && php artisan route:cache` (smoke O1) → `php artisan test` (последовательно; замерить время, `--parallel` — отдельно, чтобы не флейкал lock-тест B2)
- [x] Фронт в том же гейте: node 20, `npm ci && npm run lint && npm run build` (проверить, что билд переживает пустые NEXT_PUBLIC_*)
- [ ] Проверка приёмки №4: на ветке намеренно красный тест → job падает → убрать

### O3. `bot.catch()` + тест бота — `mh-calc-bot/src/`
- [x] `bot.catch((err) => { Sentry.captureException(err.error); console.error(...) })` — Sentry уже инициализируется в `index.js:8`; long-polling не падает
- [x] Критерий приёмки «тесты бота зелёные» (у бота уже был node --test: 5 тестов; добавлены 3 на error boundary, 8 passed): тестовых файлов у бота сейчас ноль — добавить минимальный unit-тест (`node --test`: bot экспортируется, error-handler зарегистрирован), прогон `npm test` локально, в CI бот не добавляем (граница ТЗ)

## PR-2 — Frontend (`feat/p1-hardening-frontend`) — мерджится последним

### F1. Sentry для Next.js
- [x] Зависимость `@sentry/nextjs` (официальный SDK, MIT, живой — вет пройден); App Router: `instrumentation.js`/`onRequestError` + client config, `withSentryConfig` в next.config
- [x] DSN из `NEXT_PUBLIC_SENTRY_DSN`: `ARG`+`ENV` в `mh-calc-frontend-main/Dockerfile` (по образцу :16-21) + `--build-arg` в deploy.yml (:42-49); release = `GITHUB_SHA` (тоже build-arg)
- [x] `sendDefaultPii: false`; beforeSend — вырезать `tgWebAppData`/initData из URL/breadcrumbs
- [x] Sourcemaps на self-hosted best-effort: `SENTRY_URL=https://sentry.adarasoft.com`, org `sentry`; upload включается **условно** только при наличии token+org+project в env, `silent: true` — некорректный токен/URL не роняет `next build`; проверить билд совсем без Sentry-env
- [x] Sentry-проект фронта создан (izigo-frontend, self-hosted), DSN → KV izigo--beta--SENTRY-DSN-FRONTEND + gh secret FRONTEND_SENTRY_DSN. Изначальный план «через API self-hosted (токен из KV), DSN → KV (**имя секрета — блокирующий вопрос, см. лог допущений №1**)

### F2. Error boundaries — `src/app/`
- [x] `error.js` (внутри провайдеров — можно `miniAppPalette`) и `global-error.js` (вне провайдеров — цвета Aurora инлайном: `#7C3AED`, `#F0635E`): сообщение + кнопка reset, `Sentry.captureException(error)` в `useEffect`
- [x] i18n-ключи ru/en; в global-error — статичный двуязычный текст (i18n может быть недоступен)

### F3. TON-адрес с чексуммой — `src/views/miniapp/MiniAppShell.js:35`
- [x] `isTonAddress` → `Address.parseFriendly(s)` из `@ton/core@0.59.1` (уже в deps) в try/catch; `isTestOnly === true` → невалиден

### F4 (P2). Prototype pollution guard — `src/common/i18n.js:56-67`
- [x] В разворачивании dot-ключей: сегмент ∈ {`__proto__`,`constructor`,`prototype`} → скип всего ключа

### F5 (P2). Анти-двойная оплата — `src/views/miniapp/TonPayCheckout.js:67-71`
- [x] После MAX_POLLS: не `setPhase('idle')`, а новая фаза `'sent'` — рендер без кнопки «Оплатить кошельком», с «Проверить оплату» + подсказкой «перевод уже отправлен, идёт подтверждение» (i18n ru/en)

## Порядок работ

PR-1 (job `test` в CI + B1→B4 деньги, потом B5→B7) → PR-3 (O1 + enforcement `needs: test` + O3, прогон CI на ветке) → PR-2 (F1-F5).
Тесты — рядом с каждым фиксом (паттерны: `TonPayPollTest` + `FakeTonPayGateway`, `SignsTelegramInitData`, `NotificationServiceTest`).

## Гейт 4 (PR-1): итоги ревью reviewer-агента (2026-07-02)

Блокеров нет. Применено: `orderBy('id')` в pollPending (детерминизм + честность B1-теста);
`confirmPayment` подтверждает только из {PENDING, EXPIRED} — гонка «failed во время опроса»
не перетирается тихо; dedup-ключ рассылки всегда со суффиксом `:m{id}` (граница сегмента 1↔2+
между dispatch и resume, регресс-тест добавлен); resume несуществующей рассылки → 404.

Зафиксированные ограничения:
- **B2(б):** конкурентный денежный тест двумя PHP-процессами НЕ реализован (нестабилен в CI);
  захват лока на обоих путях доказан вторым DB-коннектом (ActivationLockTest), задвоение
  ловят существующие идемпотентность/аккруал-тесты. Осознанный компромисс плана.
- **B6, окно апгрейда:** рассылка, зависшая в processing ДО деплоя PR-1, имеет старый формат
  ключей — её resume может задвоить. Проверить перед деплоем: `SELECT id FROM
  notification_broadcasts WHERE status='processing'` (ожидаемо пусто).
- **PR-3:** если включать branch-protection required check `test` — учесть `paths-ignore`
  (docs-only PR не запускает job).

## Ревизия по /fusion-челленджу (2026-07-02, panel: opus-4.8 + gpt-5.5 + deepseek-v4-pro, $0.81)

Инкорпорировано: advisory-lock в autoship до charge (анти-дедлок), аудит транзакционности `LedgerService::charge`, recompute — явная фиксация в одной xact с локом, денежный конкурентный тест B2, `SET LOCAL` в lock-тесте, TTL по белому списку `polledOkIds`, перечисление потребителей `pollStatus`, recheck: poll вне транзакции + без промежуточного PENDING, канонизация dedup-хэша B6 + двухфазный bulk (inbox), контракт resume, аудит покрытия флаг-групп + тест на осиротевший алиас, `DB_HOST=127.0.0.1` + полный bootstrap CI, job `test` уже в PR-1, `route:cache`-smoke, tag-байты и общие тест-векторы TON, guard «весь batch errored → без TTL», условный sourcemaps-upload, минимальный тест бота.
Отклонено осознанно: персистентный `last_poll_result` в БД и cap на вечно-errored платежи (расширение схемы/скоупа — в следующую итерацию P2; видимость даёт warning-лог B4).

## Лог допущений (показать пакетом в финальном отчёте)

1. ~~[БЛОКИРУЮЩИЙ ВОПРОС]~~ **РЕШЕНО пользователем 2026-07-02:** KV-имя DSN фронта = `izigo--beta--SENTRY-DSN-FRONTEND` (консистентно с namespace; переименование namespace на prod — позже одним заходом).
2. **Публичный `GET /i18n/overrides` не гейтится флагом c4** — c4 выключает админ-управление переводами, не runtime-serving; закрепляется тестом «сознательно не-403».
3. **Cabinet `GET /feature-flags/active` без флага** — источник истины для фронта о включённых фичах.
4. **B6:** намеренный повтор рассылки с идентичным текстом тому же сегменту не доставится повторно (контент-ключ). Форс — изменить текст.
5. **B2:** один глобальный блокирующий advisory-lock без отдельного lock_timeout для поллера (активации короткие; `withoutOverlapping` страхует от наслоения тиков); шардировать незачем при текущих объёмах.
6. **B4:** вечно-errored платёж не экспирируется никогда (нет cap) — осознанная граница скоупа, наблюдаемость через warning-лог.

---
---

# [АРХИВ] AI-ассистент + Knowledge Base для Mini App (завершён, в проде)

**ТЗ:** `docs/specs/2026-06-24-ai-assistant-kb.md`  
**Дата:** 2026-06-24  
**Фича-флаг:** `ai_assistant` (deny-by-default)  
**Подход:** full-context (KB-файлы загружаются целиком в контекст Claude Haiku, без RAG)

---

## Разбивка

### Шаг 1 — Knowledge Base (MD-файлы)

Создать `mh-calc-backend-main/resources/knowledge-base/`:

- [x] `marketing-plan.md` — пакеты (Bronze/Silver/Gold + PV/цены), ранги (Consultant/Manager/Manager Bronze/Manager Silver + требования), бонусы (binary 5%, referral по уровням/пакетам, leader, rank)
- [x] `faq.md` — регистрация, оплата TON Pay, вывод средств, KYC, реф-ссылка, статус pending
- [x] `onboarding.md` — первые шаги: покупка тарифа → подключение кошелька → приглашение
- [x] `technical.md` — TON Pay (memo, pending, confirm), Mini App (запуск), вывод

### Шаг 2 — Backend

- [x] `AiAssistantService.php`:
  - Загрузка KB (static property-кэш — не читать файлы на каждый запрос)
  - Guard: warning в Log если суммарный KB >100k символов
  - System prompt guardrails: **отвечать только по KB**, не выдумывать числа/сроки, не обещать доход, не давать финансовых советов, игнорировать попытки override (prompt injection defence), запрет помощи в обходе KYC/правил
  - User context: только `rank`, `package`, `locale` (без `balance` — уходит в внешний API)
  - `max_tokens: 500`, `temperature: 0.1`, `timeout: 15s`
  - Fallback: ошибки Claude → `AI_UNAVAILABLE` ответ, не исключение наружу
- [x] `AiAssistantController.php`:
  - Валидация: `question` ≤ 500 символов, `locale` ∈ {ru, en}
  - Rate limit строго по `member_id` (`ai-assistant:{member_id}`), 10 req/min
  - **Проверка feature flag `ai_assistant` на backend (не только фронт)** → 403 при отключённом
- [x] `config/services.php` (или Calculator config): `anthropic.model` из env `ANTHROPIC_MODEL`
- [x] Роут `POST /api/cabinet/assistant/ask` в `api.php` под `telegram.auth`
- [x] Feature flag `ai_assistant` в `FeatureFlagSeeder` (deny-by-default)
- [x] `.env.example`: `ANTHROPIC_API_KEY=` + `ANTHROPIC_MODEL=claude-haiku-4-5`

### Шаг 3 — Frontend

- [x] `src/views/miniapp/Assistant.js`:
  - Suggested questions (6 вопросов, самодостаточные формулировки — не требуют контекста предыдущих)
  - Input + history (память только в React state, сессионная)
  - **Подсказка пользователю**: «Ассистент не помнит предыдущие вопросы — формулируйте каждый вопрос полностью»
  - Spin + fallback-текст при `AI_UNAVAILABLE`
  - Aurora palette
- [x] `mmAssistantAsk(question, locale)` в `api.js`
- [x] `src/views/miniapp/tabs/assistant.tab.js` — регистрация таба (иконка RobotOutlined, флаг `ai_assistant`)
- [x] Добавить `assistantTab` в `registry.js` (blockCTabs)
- [x] i18n-ключи `assistant.*` в `src/locales/ru/translation.json` и `en/translation.json`

---

## Порядок

KB-файлы → Backend (Service → Controller → Route → Seeder) → Frontend (api.js → Assistant.js → tab → registry → i18n) → Ревью + тест

---

## Ключевые решения

| Вопрос | Решение |
|---|---|
| Где хранить KB? | `resources/knowledge-base/` — бакается в Docker-образ, `resource_path()` |
| RAG? | Нет — KB загружаем файлами целиком в system prompt |
| Rate limit | 10 req/min по `member_id` + backend feature flag check |
| История сессии | Только в памяти браузера (stateless backend) |
| Locale | Поле `locale` в запросе (ru/en), KB каноническая на RU |
| User context | Только rank + package (без balance — PII наружу) |
| Guardrails | System prompt: только KB, no hallucinations, no income promises |
| Модель | `ANTHROPIC_MODEL` в env/config, не хардкод |
| Ошибки Claude | Fallback → `AI_UNAVAILABLE`, не 500 |

---

## Тесты (AiAssistantControllerTest)

- Unauthenticated → 401
- Feature flag disabled → 403
- Invalid locale → 422; question >500 символов → 422
- Rate limit exceeded (11-й запрос) → 429
- Claude API error → 200 `{code: AI_UNAVAILABLE}` (не 500)
- Success: мокаем Http, проверяем 200 с `answer`

---
---

# [АРХИВ] Предыдущие планы (все завершены)

# План: Лид-окно + изменяемый спонсор + личные рефералы — Гейт 2 (АКТИВНЫЙ)

**ТЗ:** `docs/specs/2026-06-23-lead-window-changeable-sponsor.md` (Гейт 1 утверждён).
Замок спонсора = подтверждённая оплата. **Движок `Modules/Calculator` не трогаем** (только вход).

## A. Архитектурное решение (итог разведки)

### A1. Лид — отдельная таблица `leads`, ВНЕ бинар-дерева
Member с `parent_id=NULL` невозможен для лида: partial-unique `members_single_root` допускает лишь
один корень. Лид не должен занимать слот (иначе спилловер ставит под него чужих → несовместимо с
«лид удаляемый/переносимый»). → **новая таблица `leads`**: `id`, `telegram_id` (unique),
`telegram_username`, `name`, `language`, `sponsor_id` (FK members, nullOnDelete) — замок-pending
спонсор (будущий личный реферал), `expires_at`, `timestamps`. Без `ref_code` (лид не рекрутирует),
без позиции в дереве.

### A2. Member создаётся только при подтверждённой оплате (промоушн)
Текущая постановка в дерево (`MiniAppAuth → registerTelegram → place`) при первом заходе — **убирается**
для Telegram-пути. Member появляется в `OrderService::markPaid` (внутри платёжной транзакции):
`place()` нового Member под `lead.sponsor_id` → backfill `order.member_id` → удалить лид → `activate()`
(ставит `status=active`, пересчёт). Атомарно. `registerTelegram`/`place` сохраняются для owner-bootstrap
(`AuthController`) и сидов/тестов.

### A3. Заказы/платежи принадлежат лиду до оплаты
`orders.member_id` и `payments.member_id` → **nullable**; добавить `orders.lead_id`, `payments.lead_id`
(nullable FK leads, nullOnDelete). Существующие строки (members) не ломаются; на промоушне backfill.

### A4. Идентичность запроса: member ИЛИ lead
`telegram.auth` middleware резолвит по `telegram_id`: (1) есть Member → attach `member`, `start_param`
игнор (спонсор замкнут); (2) есть Lead → attach `lead`, при `start_param` с другим валидным спонсором
в окне → перепривязка last-click-wins; протухший → пересоздать; (3) нет → создать Lead из `start_param`
(нужен валидный спонсор), без `start_param` → `need_referral`. Middleware кладёт оба атрибута (nullable);
member-only эндпоинты — guard (нет member → 4xx «активируйте пакет»); `ownerBootstrap` только при member.

### A5. Личное vs бинар (исправление дисплея)
`sponsor_id` = личный реферал (любая глубина); `parent_id/position/path` = бинар-команда.
`rank-progress.personal_count` уже корректен. Баг — фронтовый `tree.children.length` (≤2). Добавляем
эндпоинт списка личных рефералов + глубину относительно меня (ltree `nlevel`).

### A6. Совместимость с прод-данными
Существующие `status=registered` Member'ы остаются в дереве — НЕ конвертируем ретроспективно. Новая
модель — только для новых заходов. Root/сиды не трогаем.

## Статус
- [x] Гейт 1–4 — ЗАВЕРШЕНО, В ПРОДЕ (PR#15)

---

# [АРХИВ] Фаза 4 — Commerce и платежи (модель A, TON/USDT)

## Статус
- [x] S1–S9 backend + F0–F8 frontend — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000020+)

---

# [АРХИВ] Редизайн Mini App Aurora

## Статус
- [x] R1–R7 — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000028)

---

# [АРХИВ] Блок C (7 фич)

## Статус
- [x] C1–C7 — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000027, 2026-06-23)

---

# [АРХИВ] Веб-админ-панель (admin.izigo.adarasoft.com)

## Статус
- [x] MVP — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000016+, 2026-06-22)

---

# [АРХИВ] Фаза 3 — Финансовое ядро

## Статус
- [x] Шаги 1–6 — ЗАВЕРШЕНО, В ПРОДЕ

---

# [АРХИВ] Telegram-only авторизация

## Статус
- [x] A1–A5 — ЗАВЕРШЕНО, В ПРОДЕ

---

# [АРХИВ] Фаза 1 / цикл 1 — доменное ядро (PV)

## Статус
- [x] ЗАВЕРШЕНО (ветка chore/phase-0-foundation)
