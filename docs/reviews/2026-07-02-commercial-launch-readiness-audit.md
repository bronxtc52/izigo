# Аудит готовности IziGo к коммерческому запуску

**Дата:** 2026-07-02
**Область:** весь продукт перед боевым выходом с реальными деньгами и пользователями
**Метод:** 5 параллельных аудитов (деньги/бэкенд, безопасность, фронт/Mini App, инфра+живой прод, данные/БД), read-only
**Вердикт:** 🔴 **NO-GO** — до запуска обязательны 5 блокеров ниже

---

## Итоговая таблица

| Направление | BLOCKER | MAJOR | MINOR |
|---|---|---|---|
| Деньги (бэкенд) | 2 | 4 | 5 |
| Фронт / Mini App | 1 | 5 | 6 |
| Инфра / прод | 2 | 6 | ~4 |
| Безопасность | 0 | 3 | 3 |
| Данные / БД | 0 | 4 | 9 |

Движок расчёта бонусов (`Modules/Calculator` ядро) верифицирован ранее и логика начислений не пересматривалась — проверялись только его вызовы и границы.

---

## 🔴 BLOCKER — без этого не запускаемся

### B-1. Бесплатная активация пакета мимо оплаты
`Modules/Calculator/Routes/api.php:88` → `CabinetController::activate` → `CabinetService::activatePackage` → `ActivationService::activate`.
Роут `POST /cabinet/activate-package` под одним `telegram.auth` — без оплаты, без admin-роли, без feature-flag. Легаси мок-оплаты Фазы 1, но с Фазы 3 `recompute()` пишет **реальные выводимые дельта-проводки в ledger** аплайну. Любой участник активирует себе топ-пакет бесплатно, аплайн получает настоящие бонусы без входящего платежа — «печать денег из воздуха» (сговор спонсор+реферал). Фронт этот endpoint уже не зовёт (`mmActivate` не используется).
**Фикс:** убрать из прод-роутов / загейтить owner-only или флагом, тестам дать config-обход.

### B-2. Отравление глобального `idempotency_key` (тот же endpoint)
`CabinetController.php:70-79` принимает произвольный клиентский `idempotency_key`, передаёт в `activate()` без префикса; ключ уникален глобально (`activation_events.idempotency_key`), а системные ключи предсказуемы (`order:{id}`). Атакующий заранее вызывает activate-package с `idempotency_key="order:12345"` → когда реальный заказ #12345 оплачен, `activate()` видит `inserted===0` и возвращает чужое событие → **жертва заплатила, активации нет, заказ помечен paid**, следа ошибки нет.
**Фикс:** закрыть endpoint (B-1) + убрать/префиксовать клиентский ключ (`client:m{id}:…`).

### B-3. «Назад» из failed-фазы чекаута → оплата по мёртвому инвойсу
`mh-calc-frontend-main/src/views/miniapp/TonPayCheckout.js:117-120` — `setPhase('idle')` возвращает «Оплатить кошельком» с **тем же** invoice/memo. Бэк уже перевёл платёж в failed/expired; poll и `/payments/{id}/check` обрабатывают только pending → перевод по протухшему memo никогда не зачтётся. Текст `pay_failed_sub` = «…Повторите оплату» прямо приглашает. Деньги ушли мерчанту, заказ не оплачен.
**Фикс:** из failed не возвращаться в idle того же инвойса — закрывать чекаут и перевыпускать инвойс (новый payment_id/memo).

### B-4. Прод-инфраструктура полностью вне мониторинга
`/etc/server-watchdog/server-watchdog.env` — `AZURE_RESOURCE_GROUPS` не содержит `rg-izigo-beta-neu`; в репо нет `ops/alerts-*.sh` (есть у всех остальных прод-проектов). Падение бэка/бота/планировщика заметит только пользователь. Для платформы с TON-платежами тихая остановка подтверждения оплат на часы — недопустима.
**Фикс:** RG в `AZURE_RESOURCE_GROUPS` + read-роли MI (MSI mh-central RG не видит — роли отдельно) + стандартный набор silent-алёртов.

### B-5. `schedule:work` без супервизии — на нём все деньги
`mh-calc-backend-main/docker/start.sh:33` — `php artisan schedule:work &` фоном; PID-1 — `artisan serve`. Планировщик умрёт (OOM/исключение) → контейнер остаётся Healthy, а `commerce:tonpay-poll` (подтверждение оплат), `notifications:outbox-dispatch`, `commerce:autoship-run`, `commerce:payouts-poll`, `leads:expire` молча встают. Health-эндпоинта «планировщик тикал недавно» нет.
**Фикс:** liveness-проба по последнему тику или вынос в ACA Cron Job.

---

## 🟠 MAJOR — до запуска или сразу после (первая неделя)

**Деньги/данные:**
- **Окно матчинга TON = последние 100 переводов** без курсора/пагинации (`TonPayGateway.php:33,62-69`). Всплеск >100 переводов на merchant-адрес между приходом и матчингом → платёж вечно pending → TTL expired; admin-recheck слеп (то же окно). Фикс: `start_utime` от `payment.created_at` + пагинация. *(Подтверждает P2-хвост «окно 100».)*
- **`pollPending` — N идентичных HTTP-запросов/мин** на каждый pending (`PaymentService.php:206-208`) → rate-limit toncenter → массовые `'error'`. Фетчить список 1 раз за тик, матчить локально.
- **Просроченный платёж лида + удаление лида** (`LeadService::expireDue:142-154` бережёт только PENDING, не expired; платёж рождается в `created`, «занятым» не считается) → FK nullOnDelete обнуляет lead_id → `markPaid` бросает «нет участника», recheck вечно падает. *(Уточняет P2-хвост «гонка лид-экспирации».)*
- **Несколько живых pending-инвойсов на заказ** (`PaymentService.php:32-57` не переиспользует pending) → двойная оплата / оплата отменённого заказа тихо no-op без алерта. Бэк-аналог фронтового F5.
- **Полный пересчёт сети на каждую активацию** под глобальным локом (`ActivationService.php:155-221`, per-row `create()`) — стена на 10k+; тик poll не влезет в минуту. Обвязку (bulk insert, инкрементальность) можно, движок не трогаем.
- **BFS-спилловер O(размер поддерева)** внутри платёжной транзакции (`AutoSpilloverStrategy`, `PlacementTree::subtreeCount`) — тысячи SELECT на оплату у спонсора возле корня. Есть ltree+GiST, но не используется; `COUNT(*) WHERE path <@ ...` заменил бы BFS.

**Фронт:**
- **Разрыв TonConnect после подписи → idle → двойная оплата** (`TonPayCheckout.js:85-88`). Уводить не в idle, а в warning + «Проверить оплату».
- **Перезагрузка/закрытие в awaiting/sent → новый memo** для того же заказа (осиротевший paid-платёж, ручной рефанд). Confirm при закрытии + переиспользовать pending на бэке.
- **Next 14.2.15 — CVE-2025-29927 (CVSS 9.1)** обход middleware + SSRF/DoS. Смягчено (middleware только host-роутинг), но поднять до последней 14.2.x (drop-in). *(P2-хвост.)*
- **Яндекс.Метрика webvisor:true в корневом layout** (`app/layout.js:65-116`) пишет DOM-сессии **админки** — TON-адреса и суммы выводов, PII, KYC уходят в Метрику. Исключить `/admin`/`admin.` из аналитики. *(P2-хвост webvisor.)*
- **Sanctum-токен админки в localStorage** (`webApi.js:10-15`) — XSS = захват сессии с правом аппрува выводов. Смягчено (нет dangerouslySetInnerHTML в админке), зафиксировать осознанно.

**Инфра:**
- **Нет health-эндпоинта** (`/up`, `/api/health` → 404) — ACA-пробы дефолтные TCP, «порт открыт» ≠ «БД жива».
- **`deploy.yml` без `concurrency`** — гонка параллельных мерджей, прод может остаться на старом sha; пост-деплой смоука нет. *(P2-хвост, однострочник.)*
- **Бот `ca-izigo-bot` вне деплой-пайплайна** — мердж изменений бота в main его не передеплоит (тихий дрифт).
- **Бэкапы Postgres 7д PITR, geo-redundancy off, restore-runbook нет** — для финансовой платформы недостаточно. *(P2-хвост retention.)*
- **root + `artisan serve` (dev-сервер) в проде** — один медленный запрос блокирует всех; capacity-риск.
- **Нет security-заголовков фронта** (CSP, X-Frame-Options, HSTS, X-Content-Type-Options), светятся `x-powered-by: Next.js` и `PHP/8.3.31`.

**Безопасность:**
- **Нет logout/revoke Sanctum** (`AuthController.php:65`, TTL 12ч) — утёкший Bearer к денежной панели нельзя отозвать. *(P2-хвост.)*
- **Replay-окно initData/Login Widget = 24ч** (`Config/config.php:11,13`) — перехваченный `X-Telegram-Init-Data` валиден сутки. Снизить до ~3600с через env. *(P2-хвост.)*
- **C5-маскирование обходится списком участников** (`AdminService.php:71,72`) — support/finance/leader видят `telegram_username`/`ref_code` в обычном `admin/members` мимо регламента. *(P2-хвост C5.)*

---

## 🟡 MINOR — техдолг, не блокирует запуск

- Нет выделенного throttle на логин/выводы/платежи/рассылки (общий per-IP бакет 60/мин).
- 500 (не 4xx) при заходе лида на member-only cabinet-фичи (`TicketController.php:139-142`, нет null-guard).
- Легаси структурные роуты `calculator.validate.token` — мёртвый код, снести/задокументировать.
- Пометка `failed` без лока и re-check статуса (мина под будущий драйвер); `pollStatus==='none'` попадает в polledOkIds → TTL при потерянных env.
- `FakeGateway` с пустым webhook-secret — запретить fake-драйверы при isProduction().
- `PlacementAdminService::move` меняет вход движка без advisory-lock.
- Нет индекса `status` на payments (seq-scan поллера каждую минуту); `orders.idempotency_key` глобально-уникален при per-member проверке → 500 при коллизии.
- `cascadeOnDelete` на ledger/payments/orders — одна ручная SQL снесёт финансовую историю (лучше restrictOnDelete на ledger).
- Retention отсутствует у outbox/inbox/audit_log/raw_payload (распухнут на 10k×рассылки).
- Двойная оплата заказа возможна на уровне БД (нет partial-unique «один живой платёж на order»).
- Админ-выборки без пагинации (withdrawals/reportUsers/reportBalances — вся таблица).
- CACHE_DRIVER=file → 2 реплики сломают `withoutOverlapping` (сейчас maxReplicas=1, только договорённость).
- `izigo--prod--ANTHROPIC-API-KEY` — разъезд env-нейминга (prod vs beta).
- Комментарий про skipLocked в OutboxDispatcher врёт (обычный lockForUpdate).
- Ручная «Проверить оплату» при pending без фидбека; `fallbackLng:'kk'` без секции miniapp; keyless toncenter (~1 rps) → 429 под нагрузкой.

---

## ✅ Что уже в порядке (не сломать при правках)

- **Ledger:** двойная запись + assertBalanced, идемпотентность по unique-ключу, кэш баланса под row-lock в той же транзакции, отрицательный available невозможен.
- **Платёжный контур:** точный матч memo (не подстрока), переплата принимается, недоплата не финализируется, `confirmPayment` идемпотентен под lockForUpdate только из PENDING/EXPIRED, TTL защищён guard'ом «все опросы упали» + Sentry.
- **Advisory-lock:** все живые пути к activate() (webhook/poll/recheck/autoship) держат единый порядок; autoship — списание+заказ+активация одной транзакцией с откатом.
- **Withdrawals:** статус-машина под row-lock с whitelist, hold/release/markPaid идемпотентны, TON-адрес валидируется (CRC16+testnet reject), KYC-гейт.
- **Auth:** initData HMAC(«WebAppData»,token) и Login Widget SHA256(token) корректны, hash_equals, fail-closed на пустом initData; IDOR закрыт скоупом member_id/lead_id; RBAC deny-by-default; feature-flags deny-by-default.
- **Лид-промоушен:** backfill заказов/платежей ДО удаления лида (основной путь чист).
- **Уведомления:** dedup unique в outbox+inbox, chunk-транзакции, resume без дублей, reaper зависших sending.
- **Секреты:** рабочее дерево и вся git-история (130 коммитов) чисты; .env в .gitignore; SENTRY_SEND_DEFAULT_PII=false; бот-токен только из KV.
- **Фронт:** error boundaries (F2), Sentry с скрабом initData (F1), анти-двойная оплата фаза sent (F5), prototype-pollution guard (F4), XSS-поверхность закрыта (единственный dangerouslySetInnerHTML санитайзится на бэке), позиционные args без регресса, авто-логаут 401.
- **CI/деплой:** гейт needs:test (реальный Postgres, artisan test, фронт lint+build), OIDC без паролей, start.sh строгий без `|| true`, миграции выполнимы с нуля, сидеры идемпотентны.
- **Живость прода:** оба фронт-домена 200/HTTPS, бэк отвечает, бот вычитывает апдейты, CI-история стабильна.

---

## Рекомендованный порядок работ

1. **Блокеры-деньги (B-1+B-2)** — один фикс endpoint activate-package. Полдня.
2. **Блокер-фронт (B-3)** + связка MAJOR (разрыв TonConnect, повторная оплата) — «отправлено ≠ idle». Один цикл.
3. **Блокеры-инфра (B-4 мониторинг, B-5 супервизия планировщика)** + M1 health-эндпоинт — один пакет ops.
4. **Дешёвые MAJOR:** concurrency в deploy.yml (однострочник), окно матчинга TON (start_utime), webvisor исключить admin, Next bump, security-заголовки, logout Sanctum, max_age initData env.
5. **Остальные MAJOR** (пересчёт/BFS-производительность, бэкапы-runbook, бот в пайплайн) — первая неделя после запуска, по мере роста нагрузки.

MINOR — плановый техдолг, на решение о запуске не влияют.
