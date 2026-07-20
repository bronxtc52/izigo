# Гейт A: планы блока «P2-хвосты IziGo» (2026-07-20)

**Оркестрация:** /armada, Workflow #1 (`wf_bbc5c8e6-f0b`) — preflight + 3 параллельных Plan-агента.
**Integration-ветка:** `release/p2-tails`. **Запретные зоны:** ядро `Modules/Calculator` (V1 Domain/ и V2/) — только чтение/вызов.
**Статус:** ожидает решения владельца (Гейт A). Мердж в main = прод-деплой — отдельный прод-гейт.

---

## t1-admin-cookie-auth — Веб-админка: Sanctum-токен из localStorage → httpOnly cookie-сессия (рекомендация: Next.js BFF-proxy)

### Таблицы/колонки
- НЕТ новых/изменяемых таблиц при рекомендуемом варианте (а) BFF-proxy: серверные сессии не вводятся, Sanctum personal_access_tokens используется как есть (токен просто перестаёт попадать в JS).
- Только при варианте (б) Sanctum SPA: понадобилась бы таблица sessions (driver=database), т.к. SESSION_DRIVER=file в контейнере теряет сессии при каждом редеплое — ещё один аргумент против (б).

### Миграции (по порядку)
- Миграций нет (вариант а). Это снимает риск пересечения timestamp-префиксов с миграцией t2 (last_poll_result в payments).

### Backend
- ИЗМЕНЕНИЙ BACKEND НЕ ТРЕБУЕТСЯ при варианте (а) — прокси подставляет Authorization: Bearer server-side, а WebAdminAuth (Modules/Calculator/Http/Middleware/WebAdminAuth.php) уже аутентифицирует строго по bearerToken(). Контрактные файлы только ЧИТАЮТСЯ: AuthController.php (telegramLogin возвращает {status,token,member.roles}; logout с ?all=1), Routes/api.php:23 (auth/telegram-login) и :147 (admin/auth/logout).
- config/cors.php НЕ трогаем (allowed_origins ['*'] + supports_credentials=false остаются — cookie никогда не уходит cross-site, Mini App не задет). config/sanctum.php НЕ трогаем.
- ОПЦИОНАЛЬНО (решение гейта, вопрос №2): ужесточить выдачу bearer-токена только для прокси — например, secret-заголовок X-Admin-Proxy-Key на POST auth/telegram-login (проверка в AuthController или отдельный middleware). Без этого прямой Bearer-доступ к API остаётся возможен (но токен больше нигде в JS не живёт — приемлемо).
- Вариант (б) для сравнения (НЕ рекомендован): раскомментировать EnsureFrontendRequestsAreStateful (app/Http/Kernel.php:42), SANCTUM_STATEFUL_DOMAINS=admin.izigo.adarasoft.com, guard web с провайдером Member (сейчас провайдер users → App\Models\User — нужен кастомный), Auth::login($member) в telegramLogin, SESSION_DRIVER=database + миграция sessions, cors.php: явные origins (включая izigo.adarasoft.com для Mini App!) + supports_credentials=true, CSRF-флоу /sanctum/csrf-cookie, WebAdminAuth учить принимать session-auth, плюс инфра: DNS CNAME api.izigo.adarasoft.com + az containerapp hostname bind на ca-izigo-backend + managed cert + пересборка фронта с новым NEXT_PUBLIC_SERVER_BACK_URL (заденет и Mini App base URL). Blast radius на порядок больше.

### Фронт: админка
- НОВЫЙ src/app/api/v1/_lib/adminSession.js (server-only): seal/unseal токена AES-256-GCM ключом из runtime env ADMIN_COOKIE_SECRET; константы cookie (имя izigo_admin_s, HttpOnly, Secure, SameSite=Lax, Path=/api/v1, Max-Age=720*60 в синхроне с WEB_ADMIN_TOKEN_TTL_MINUTES); резолв базы бэка: process.env.BACKEND_INTERNAL_URL || NEXT_PUBLIC_SERVER_BACK_URL; host-guard (только admin.* или localhost — зеркало логики src/middleware.js).
- НОВЫЙ src/app/api/v1/auth/telegram-login/route.js (POST): принимает payload Telegram Login Widget, server-side fetch на {BACKEND}/api/v1/auth/telegram-login; при ok — Set-Cookie с запечатанным токеном, в ответ клиенту {status, member} БЕЗ token; при 401/403 бэка — транслировать статус, cookie не ставить.
- НОВЫЙ src/app/api/v1/admin/[...path]/route.js (GET/POST/PUT/PATCH/DELETE): generic-прокси ТОЛЬКО admin-неймспейса → {BACKEND}/api/v1/admin/<path>?<qs>; unseal cookie → Authorization: Bearer; стриминг тела ответа (нужно для CSV exportMember); транслировать статус/Content-Type/Content-Disposition; на 401 бэка — очистить cookie + 401 {need_login}; CSRF defense-in-depth: для не-GET проверять Origin/Sec-Fetch-Site (same-origin | admin-host), иначе 403. Внутри — logout-путь /admin/auth/logout дополнительно чистит cookie после ответа бэка (интерфейс webApi.logout не меняется).
- src/views/admin/webApi.js: req()/exportMember — база API_SERVER_URL → '' (same-origin, cookie уходит автоматически); убрать Authorization-заголовок и весь TOKEN_KEY/getToken/setToken (сигнатуру req(path, token, method, body) СОХРАНИТЬ, token игнорировать — тогда 20+ вьюх и refundsApi.js не меняются); clearToken → чистит только ROLES_KEY + legacy-ключ izigo_admin_token (одноразовая зачистка старых токенов из localStorage у действующих админов); logout — как был, через req().
- src/views/admin/web/v2/apiV2.js: call() — та же правка (база '', без Bearer/getToken), handleUnauthorized оставить.
- src/app/admin/login/page.js: fetch на '/api/v1/auth/telegram-login' (same-origin вместо API_SERVER_URL); убрать setToken(data.token); setRoles(data.member.roles) оставить (роли — UI-подсказка, не секрет; RBAC на бэке).
- src/app/admin/layout.js: гейт if (!getToken()) заменить на маркер логина: getRoles().length>0 (или нехваткой — best-effort GET /api/v1/admin/dashboard); httpOnly cookie из JS не читается — это ожидаемо, реальная защита на бэке.
- src/views/admin/web/WebAdminShell.js: creds={webApi.getToken()} → creds={undefined} (или убрать проп; AdminWithdrawals/MembersList уже зовут api.req с token-first undefined).
- src/middleware.js: добавить '/api/v1/:path*' в matcher + правило: api-прокси-пути на НЕ-admin-хосте (кроме localhost) → 404 (Mini App ходит на бэк напрямую по абсолютному URL — коллизии нет, но глушим поверхность).
- ДЕПЛОЙ: .github/workflows/deploy.yml — прокинуть на ca-izigo-frontend runtime-секрет ADMIN_COOKIE_SECRET (az containerapp secret set + --set-env-vars, по образцу бэка) и BACKEND_INTERNAL_URL=secrets.BACKEND_URL; DEPLOY.md §11 — дополнить чек-лист (секрет, отсутствие token в localStorage как критерий приёмки).

### Фронт: miniapp
- Изменений НЕТ. Mini App (src/views/miniapp, initDataApi.js, cabinet) продолжает ходить на бэк напрямую по NEXT_PUBLIC_SERVER_BACK_URL с header-auth; CORS ['*'] без credentials не меняется. Единственная точка касания — src/middleware.js (общий host-routing): правка матчера должна быть проверена смоуком Mini App.

### Общие файлы (риск конфликта)
- mh-calc-frontend-main/src/views/admin/webApi.js — t1 меняет транспорт req(); t2 добавляет вызовы/поля по платежам (last_poll_result). Главная точка конфликта блока.
- mh-calc-frontend-main/src/middleware.js — общий host-routing админки и Mini App.
- .github/workflows/deploy.yml — t1 добавляет секрет/env фронта.
- DEPLOY.md — t1 дополняет §11.
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — при варианте (а) t1 его НЕ трогает (риск конфликта с t2 по admin-блоку снимается); при варианте (б) — трогает.
- config/cors.php, config/sanctum.php, app/Http/Kernel.php, .env.example бэка — затрагиваются ТОЛЬКО при варианте (б).

### Риски конфликтов
- webApi.js: если t2 в параллельной ветке добавит fetch-функции платежей — текстовый merge-конфликт с правкой req(). Митигируется тем, что t1 сохраняет сигнатуру req(path, token, method, body) и не переименовывает экспорты — конфликты только построчные, семантических нет.
- deploy.yml: параллельные правки CI другими задачами блока — координировать порядок мерджа; шаг t1 (секрет фронта) изолирован в frontend-джобе.
- Выбор варианта (б) на гейте резко расширяет пересечения: Routes/api.php, cors.php, Kernel.php, .env.example — тогда t1 обязан мерджиться ПЕРВЫМ в merge train, до t2.
- Прокси и legacy-Bearer сосуществуют до истечения TTL (12ч): старые токены в localStorage админов работают напрямую на бэк, пока не сработает зачистка clearToken/legacy-ключа или ручной revoke (вопрос №4).
- Правка src/middleware.js — общая с Mini App поверхность: ошибка в matcher может зарубить '/' или '/miniapp' на прод-хосте; обязателен смоук обоих хостов после выката.

### Тест-план
- Backend-регрессия: php artisan test полностью зелёный БЕЗ изменений бэкенда (изменений и нет — это и проверяем).
- Login happy-path (admin.izigo.adarasoft.com или localhost): вход через виджет → ответ БЕЗ поля token; Set-Cookie: izigo_admin_s с HttpOnly; Secure; SameSite=Lax; в localStorage НЕТ izigo_admin_token; document.cookie НЕ показывает izigo_admin_s.
- NEGATIVE login: битая подпись виджета → 401, cookie НЕ ставится; участник без админ-ролей → 403, cookie НЕ ставится (проверка curl с фейковым payload).
- NEGATIVE доступ: GET /api/v1/admin/dashboard без cookie → 401 + редирект UI на /admin/login; подделанная/обрезанная cookie (tamper байтов) → unseal fail → 401 + Set-Cookie очистки; отозванный/протухший на бэке токен → 401 сквозь прокси, UI уводит на логин без цикла.
- CSRF: curl POST на /api/v1/admin/withdrawals/{id}/approve с валидной cookie, но Origin: https://evil.example → 403; проверить, что cross-site form-POST не несёт cookie (SameSite=Lax).
- Host-изоляция: те же прокси-пути на https://izigo.adarasoft.com → 404; Mini App смоук на izigo.adarasoft.com (initData-auth, каталог, оплата TON) — без регрессий, CORS не менялся.
- Прокси-скоуп: /api/v1/structure* и прочие не-admin пути через прокси НЕ доступны (404) — проксируется только admin-неймспейс + telegram-login.
- Функциональный прогон админки через прокси: dashboard, members (+PII reveal), withdrawals approve/reject (денежные POST), V2-вкладки (apiV2.js), refunds (refundsApi через req), CSV/JSON exportMember — файл скачивается (стриминг работает).
- Logout: POST logout → повторный запрос через прокси 401 (токен отозван на бэке), cookie очищена; ?all=1 отзывает все токены участника.
- TTL: cookie Max-Age == WEB_ADMIN_TOKEN_TTL_MINUTES; по истечении токена (сжать TTL в тесте) прокси отдаёт 401, а не 500.
- Фронтовой тест-инфраструктуры (jest/playwright) в репо нет — план выше выполняется curl-чек-листом + ручным прогоном; при желании гейта можно добавить unit-тесты seal/unseal на node:test без новых зависимостей.

### Вопросы к гейту
- РАЗВИЛКА №1 (главная): вариант (а) Next BFF-proxy (рекомендую: ноль изменений бэкенда/CORS/Mini App, нет DNS/ACA-операций, нет session-инфраструктуры; минус — прокси-хоп и Next-сервер становится auth-критичным) или (б) api.izigo.adarasoft.com + Sanctum SPA stateful (плюс — «каноничный» Laravel-механизм; минусы — кастомный guard под Member, SESSION_DRIVER=database+миграция, явные CORS-origins с задеванием Mini App, DNS+cert+пересборка фронта, мердж-конфликты с t2 по Routes/api.php)?
- РАЗВИЛКА №2: оставить ли на бэке прямой Bearer-приём (WebAdminAuth) как есть? Рекомендую да — контракт не трогаем, токен в JS больше не живёт. Альтернатива: секрет-заголовок прокси на telegram-login, чтобы токен нельзя было получить в обход прокси (+бэкенд-правка, +env).
- Операционный: ADMIN_COOKIE_SECRET — заводим как GitHub secret с прокидкой в ACA через deploy.yml (рекомендую) или одноразово руками az containerapp secret set? Нужен доступ к rg-izigo-beta-neu.
- Принудительный revoke всех существующих web-admin токенов при выкате (одноразовая команда/tinker: PersonalAccessToken where name=web-admin delete) — делать, или дать старым bearer-токенам в localStorage админов дожить TTL до 12ч? Рекомендую revoke: мгновенно закрывает XSS-окно, цена — один re-login админов.
- Роли в localStorage остаются как UI-подсказка для меню (не секрет, RBAC форсится бэком на каждом запросе) — подтвердить, что не требуется переносить и их в cookie/серверный ответ.

### Допущения
- Фактический путь клиента — mh-calc-frontend-main/src/views/admin/webApi.js (в постановке указан src/common/webApi.js — такого файла НЕТ, в src/common только utils/i18n/GlobalContext).
- adarasoft.com и azurecontainerapps.io — разные регистрируемые домены (*.azurecontainerapps.io к тому же в Public Suffix List) → первосторонняя cookie для прямого XHR на бэк не поедет (third-party, блокируется Safari/Chrome) — подтверждено как факт, оба варианта (а)/(б) это обходят.
- Фронт задеплоен как Next standalone Node-сервер в ACA (next.config.mjs: output 'standalone'; deploy.yml обновляет ca-izigo-frontend) → Route Handlers исполняются server-side и читают runtime env контейнера; сейчас у фронт-контейнера runtime-секретов нет (только build-args) — добавление ADMIN_COOKIE_SECRET обязательно.
- admin.izigo.adarasoft.com и izigo.adarasoft.com обслуживает ОДИН фронт-апп (host-routing в src/middleware.js; DEPLOY.md §10-11) — прокси автоматически same-origin для админки без новой инфраструктуры.
- NEXT_PUBLIC_SERVER_BACK_URL инлайнится при сборке и доступен серверному коду; для устойчивости добавляем runtime BACKEND_INTERNAL_URL с fallback.
- Потребители токена на фронте исчерпываются: webApi.js, apiV2.js (свой call с getToken), refundsApi.js (через req), login page (setToken), admin/layout.js (гейт getToken), WebAdminShell (creds/getRoles/logout) — grep подтверждён, других мест нет.
- Logout-контракт уже существует (P2-hardening C2): POST /api/v1/admin/auth/logout (?all=1) — переиспользуется через прокси без изменений.
- Sanctum SPA-предпосылки для (б) сейчас отсутствуют: EnsureFrontendRequestsAreStateful закомментирован (app/Http/Kernel.php:42), guard web не про Member, SESSION_DRIVER=file — оценка трудоёмкости (б) исходит из этого.
- Экспорт CSV (webApi.exportMember) — единственный не-JSON ответ admin-API; прокси обязан стримить тело и заголовки Content-Disposition.
- Sentry-туннеля и WebSocket/SSE в админке нет — прокси покрывает 100% admin-трафика.

---

## t2-payment-poll-observability — Персистентный last_poll_result + cap вечно-errored платежей (эскалация вместо авто-экспирации)

### Таблицы/колонки
- payments (ALTER, аддитивно): + last_poll_result VARCHAR(16) NULL (значения paid|pending|failed|none|error — та же семантика, что у PaymentGateway::pollStatus; НЕ enum-check, чтобы не плодить миграции при расширении), + last_polled_at TIMESTAMPTZ NULL, + consecutive_poll_errors INTEGER NOT NULL DEFAULT 0 (сбрасывается в 0 при любом успешном опросе paid/pending/failed/none; integer, не smallint — при опросе раз в минуту smallint переполнился бы за ~45 дней страйка). Опционально (по объёму таблицы — сейчас не нужно): частичный индекс ON payments (status) WHERE consecutive_poll_errors > 0 для фильтра админки. Инвариант B4 сохранён: payments.status по-прежнему НИКОГДА не принимает 'error' — 'error' живёт только в last_poll_result.

### Миграции (по порядку)
- 2026_07_20_010000_add_poll_observability_to_payments_table.php — единственная миграция задачи: Schema::table('payments') добавляет last_poll_result (string 16, nullable), last_polled_at (timestampTz, nullable), consecutive_poll_errors (integer, default 0); down() — dropColumn трёх колонок. Каталог: mh-calc-backend-main/Modules/Calculator/Database/Migrations/ (последняя существующая — 2026_07_14_200200_*, конфликтов префиксов нет; t1/t3 миграций не планируют, но при параллельных ветках держать дату >= 2026_07_20).

### Backend
- Modules/Calculator/Models/Payment.php — добавить 3 колонки в $fillable и $casts (last_polled_at => datetime, consecutive_poll_errors => integer) + PHPDoc-@property; констант новых статусов НЕ добавлять (это не статусы платежа)
- Modules/Calculator/Services/PaymentService.php::pollPending() (строки 285-383) — главное изменение. (a) В цикле собирать три корзины: $erroredIds (status==='error', сейчас голый continue на 313-316), $polledOkIds (уже есть), финализированные paid/failed. (b) После цикла — ДВА батч-апдейта вместо per-row save: UPDATE payments SET consecutive_poll_errors = consecutive_poll_errors + 1, last_poll_result='error', last_polled_at=now() WHERE id IN ($erroredIds) и UPDATE ... SET consecutive_poll_errors=0, last_poll_result=<статус>, last_polled_at=now() WHERE id IN ($polledOkIds) (для polledOk писать фактический 'pending'/'none' — можно двумя подмножествами или через CASE; допустимо упростить до 'pending'); для paid/failed — проставить поля в существующих индивидуальных ветках (paid — через confirmPayment не проходит эти колонки, поэтому апдейт колонок отдельным update по id после confirmPayment/save). (c) Эскалация порога: N = config('calculator.payment_poll_error_threshold'); после инкремента выбрать id, у которых счётчик ПЕРЕСЁК порог именно в этом тике (значение == N после инкремента — одно событие на страйк, без повторов на N+1, N+2...); если список непуст — Log::warning('tonpay-poll: платежи с >=N ошибками опроса подряд', [ids]) + ОДИН агрегированный \Sentry\captureMessage (warning) на тик по образцу TTL-блока (строки 362-374) — при падении индексатора целиком (allPollsFailed) это даёт один event, а не шторм по каждому платежу; счётчики при allPollsFailed всё равно инкрементим (честная история, после восстановления индексатора успешный опрос их сбросит). (d) Вернуть в summary новый ключ 'escalated' => count. (e) НИКАКОЙ авто-экспирации по порогу: cap = только эскалация; зафиксировать это комментарием у порога (деньги могли прийти — экспирация закрыла бы подхват поздней оплаты по memo, признанная граница P1/B4)
- Modules/Calculator/Services/PaymentService.php::recheckAdmin() (строки 392-411) — после pollStatus записывать исход в те же колонки: 'error' → инкремент счётчика + last_poll_result/last_polled_at; успешный опрос (paid/pending/failed/none) → сброс счётчика в 0. Смысл: успешный админ-recheck снимает маркер «проблемный опрос» и возвращает платёж в нормальный жизненный цикл (следующий успешный тик крона сможет TTL-экспирировать его штатно через polledOkIds). Пользовательские checkForMember/checkForLead НЕ трогаем (минимальная поверхность; вопрос к гейту)
- Modules/Calculator/Services/AdminReportService.php::payments() (строки 89-112) — расширить маппер полями last_poll_result, last_polled_at (ISO8601), consecutive_poll_errors и вычисляемым poll_problem (bool: consecutive_poll_errors >= порог); добавить фильтр poll_problem=1 → where('consecutive_poll_errors','>=',N) (+прокинуть ключ в AdminReportController::payments() $request->only([...,'poll_problem']))
- Modules/Calculator/Http/Controllers/AdminReportController.php::payments() (строки 39-44) — добавить 'poll_problem' в whitelisted-параметры; новых роутов НЕ добавлять (фильтр — query-параметр существующего GET /admin/payments; recheck POST /admin/payments/{id}/recheck уже существует, Routes/api.php:179-183 не трогаем — снижает конфликт с t1)
- Modules/Calculator/Config/config.php — новый ключ 'payment_poll_error_threshold' => (int) env('PAYMENT_POLL_ERROR_THRESHOLD', 10) рядом с payment_pending_ttl_minutes (строка 59); 0 = эскалация выключена (deny-off guard в коде). Poll идёт everyMinute → дефолт 10 ≈ 10 минут непрерывных ошибок
- .env.example — строка PAYMENT_POLL_ERROR_THRESHOLD=10 рядом с PAYMENT_PENDING_TTL_MINUTES
- Modules/Calculator/Console/TonPayPollCommand.php — дописать escalated=... в строку info() (опционально, косметика)
- Modules/Calculator/Services/Payment/FakeTonPayGateway.php — изменений НЕ требуется: failFor/$failAll уже возвращают 'error' и в pollStatus, и в pollBatch — вся новая логика тестируется существующими хелперами; статический reset() уже чистит состояние

### Фронт: админка
- mh-calc-frontend-main/src/views/admin/webApi.js — (a) fetchPayments уже принимает params — новых функций для фильтра не нужно; (b) добавить recheckPayment(token, id) → POST /api/v1/admin/payments/{id}/recheck (по образцу существующих POST-хелперов) — если гейт одобрит кнопку эскалации в таблице
- mh-calc-frontend-main/src/views/admin/web/Operations.js → PaymentsTab (строки 7-33) — (a) колонка «Опрос»: маркер для poll_problem (Tag color=warning, tooltip с consecutive_poll_errors и last_polled_at), иначе last_poll_result/'—'; (b) фильтр-переключатель «проблемный опрос» (Switch/Segmented) → перезапрос fetchPayments({ poll_problem: 1 }); (c) при одобрении гейтом — кнопка «Recheck» в строке для pending/expired (вызов api.recheckPayment, message об исходе, refetch); учесть RBAC: список доступен owner/finance/support, recheck — только owner/finance (кнопку показывать всем, 403 обработать как message.error, либо скрыть по ролям из webApi.getRoles())

### Фронт: miniapp
- Работ нет: пользовательская семантика не меняется — для юзера 'error' по-прежнему неотличим от pending (checkForMember/checkForLead не трогаем), новые поля — только админская наблюдаемость

### Общие файлы (риск конфликта)
- mh-calc-backend-main/Modules/Calculator/Routes/api.php — t2 его НЕ правит (осознанно: фильтр через query-параметр, recheck-роут существует) → конфликт с t1 снят; если в ходе реализации всё же понадобится роут — добавлять строго в конец payments-подблока (строки 179-183)
- mh-calc-frontend-main/src/views/admin/webApi.js — t1 переписывает транспорт req() (Bearer→cookie), t2 добавляет 1 функцию recheckPayment; правка t2 аддитивна и не трогает req() — мерджится, но координировать порядок веток
- mh-calc-backend-main/Modules/Calculator/Config/config.php — t1 (cookie/token TTL) и t2 (payment_poll_error_threshold) добавляют ключи в один массив; t2 кладёт ключ в секцию 'Фаза 4 commerce' у payment_pending_ttl_minutes — разные секции файла, конфликт маловероятен
- mh-calc-backend-main/.env.example — t1 и t2 добавляют соседние строки; мелкий rerere-конфликт
- mh-calc-backend-main/Modules/Calculator/Database/Migrations/ — порядок timestamp-префиксов: t2 берёт 2026_07_20_010000; t1/t3 миграций не планируют
- mh-calc-backend-main/Modules/Calculator/Console/TonPayPollCommand.php и Providers/CalculatorServiceProvider.php — t2 расписание/регистрацию НЕ меняет (только текст info в команде), t3 может регистрировать bench-команду в провайдере — не пересекаются по строкам

### Риски конфликтов
- webApi.js: если t1 смёрджится первым и изменит сигнатуру req()/уберёт token-параметр — recheckPayment из t2 писать по НОВОМУ паттерну; при обратном порядке t1 механически перепишет и функцию t2 (низкий риск, но проговорить в merge train)
- config.php/.env.example: тривиальные line-level конфликты при параллельных ветках t1/t2 — разрешаются объединением строк
- Тестовая гонка по FakeTonPayGateway: статическое состояние ($onchain/$failMemos/$failAll/$fetchCalls) — новые тесты ОБЯЗАНЫ звать FakeTonPayGateway::reset() в setUp/tearDown по образцу TonPayPollTest, иначе флаки у соседних сьютов
- Существующие B4-тесты PaymentHardeningTest (testPollErrorPreventsExpiration, testIndexerOutageSkipsTtlEntirely, testCheckEndpointKeepsPendingOnPollError, testAdminRecheckErrorKeepsExpired) ассертят только status — аддитивные колонки их не ломают, но прогнать весь сьют обязательно; риск — если батч-апдейт errored-ids случайно заденет TTL-ветку (polledOkIds must stay источником правды для экспирации)
- Sentry в тестах: DSN пуст → captureMessage no-op; ассерты эскалации делать через Log::spy/shouldReceive('warning'), не через Sentry (иначе тест ничего не проверяет)
- Батч-UPDATE errored-платежей выполняется вне row-lock: гонка с confirmPayment (платёж стал paid между фетчем и апдейтом) безвредна — колонки observability-only, статус не трогаем; зафиксировать комментарием

### Тест-план
- [деньги/B4, обязательный negative] Инвариант B4: платёж с failFor, прогнанный tonpay-poll (N+5) раз при пороге N — payments.status остаётся PENDING (никогда 'error'/'expired'/'failed'), при этом consecutive_poll_errors == N+5, last_poll_result == 'error', last_polled_at заполнен
- [деньги, обязательный negative] Cap без авто-экспирации: errored-платёж старше payment_pending_ttl_minutes и выше порога N — после тика status == PENDING (не EXPIRED); контроль против регрессии testPollErrorPreventsExpiration
- [деньги] Поздняя оплата после страйка ошибок: failFor снят + fakePay → следующий тик подтверждает платёж (PAID), fulfillment проходит, счётчик обнулён — доказательство, что cap не закрыл окно подхвата денег
- Сброс счётчика: платёж с накопленными ошибками, failFor снят, перевода нет → тик пишет last_poll_result='pending', consecutive_poll_errors=0; далее stale-платёж штатно экспирируется TTL на успешном опросе (контроль testSuccessfulPollStillExpiresStale остаётся зелёным)
- Эскалация ровно один раз на страйк: порог N=3, три тика с failFor → Log::warning об эскалации на третьем тике (Log::spy); четвёртый тик (счётчик 4) — повторного warning НЕТ; summary команды содержит escalated=1 на пересечении
- allPollsFailed (FakeTonPayGateway::$failAll): счётчики ВСЕХ pending инкрементятся, TTL пропущен (существующий guard), эскалация — одним агрегированным событием/логом на тик, не по-платёжно
- recheckAdmin: (a) успешный recheck (poll='pending') сбрасывает счётчик; (b) recheck с failFor инкрементит счётчик и оставляет статус (testAdminRecheckErrorKeepsExpired расширить ассертами колонок); (c) существующие recheck-тесты идемпотентности/RBAC зелёные
- Admin API: GET /admin/payments возвращает last_poll_result/last_polled_at/consecutive_poll_errors/poll_problem; фильтр poll_problem=1 отдаёт только платежи со счётчиком >= N; [auth negative] запрос без токена → 401, роль support видит список (существующий RBAC), recheck под support → 403 (testAdminRecheckDeniedForSupport остаётся)
- Порог 0 (env PAYMENT_POLL_ERROR_THRESHOLD=0): эскалация выключена — счётчики пишутся, Sentry/Log-эскалации нет, poll_problem всегда false (или фильтр отключён — по выбранной семантике, зафиксировать тестом)
- Полный прогон существующих TonPayPollTest + PaymentHardeningTest + PaymentWebhookTest без правок их ассертов (кроме аддитивных расширений) — php artisan test
- Frontend (ручной смоук): вкладка Операции → Платежи показывает маркер у платежа с poll_problem, фильтр работает, recheck-кнопка (если одобрена) вызывает POST и обновляет строку; 403 для support обработан без белого экрана

### Вопросы к гейту
- Дефолт порога N (PAYMENT_POLL_ERROR_THRESHOLD): poll идёт каждую минуту — предлагаю 10 (≈10 минут непрерывных ошибок). И нужен ли re-fire повторного Sentry-события для НЕ починенного страйка (например, каждые N*k ошибок), или одного события на страйк + постоянного маркера в админке достаточно? (рекомендация: одно на страйк, без re-fire — Sentry-алертинг по unresolved issue закрывает повторность)
- Кнопка «Recheck» прямо в таблице платежей админки (роут уже существует) — включаем в объём t2 как путь эскалации, или t2 ограничивается маркером/фильтром, а recheck остаётся доступен только через существующие механизмы? (рекомендация: включить — это и есть заявленная 'эскалация к админ-ручке')
- Записывать ли poll-исход из пользовательских ручек checkForMember/checkForLead (успешный user-check сбрасывал бы счётчик)? (рекомендация: НЕТ, только крон pollPending + admin recheckAdmin — минимальная поверхность и меньше правок B4-тестов; user-пути не меняются)
- Семантика при allPollsFailed (индексатор лежит целиком): инкрементить счётчики всех платежей (честная история, массовое достижение порога за N минут аутэйджа) или замораживать per-payment счётчики и эскалировать отдельным событием «indexer down»? (рекомендация: инкрементить + один агрегированный Sentry-event на тик; после восстановления успешный опрос сбрасывает счётчики сам)

### Допущения
- Инвариант B4 незыблем: 'error' пишется ТОЛЬКО в новую колонку last_poll_result, payments.status не расширяется и константа STATUS_ERROR не вводится
- Cap = эскалация, не экспирация: авто-expire по N ошибок запрещён (деньги могли прийти, опрос лишь не смог это проверить; экспирация закрыла бы подхват поздней оплаты по memo) — решение фиксируется комментарием в коде у порога
- Новые роуты не нужны: фильтр — query-параметр существующего GET /api/v1/admin/payments; POST /admin/payments/{id}/recheck уже существует с RBAC owner/finance
- FakeTonPayGateway менять не нужно — failFor/$failAll покрывают все новые сценарии; 'failNext' из постановки в коде отсутствует (фактические хелперы: failFor(memo) и $failAll) — считаю это неточностью постановки, не недостающим API
- Объём таблицы payments умеренный → батч-апдейты без чанкинга и без нового индекса приемлемы; частичный индекс — отложенная оптимизация
- Sentry-эскалация — по образцу существующего TTL-блока (\Sentry\captureMessage + Log::warning); в тестах Sentry no-op, ассерты через Log
- Расписание команды (everyMinute) и контракт PaymentGateway не меняются; запретные зоны (Domain/CompensationEngine, V2, валютная логика) не затрагиваются
- Miniapp-работ нет; фронт-объём ограничен вкладкой Operations.js и одной функцией в webApi.js

---

## t3-engine-perf-benchmark — Перф движка пересчёта: воспроизводимый бенчмарк (measurement-first), без оптимизаций

### Backend
- NEW Modules/Calculator/Support/Bench/SyntheticTreeGenerator.php — детерминированный генератор дерева N участников: bulk-insert чанками, поля id-порядок/sponsor_id/parent_id/position/path(ltree)/package_id/status=active; guards: отказ при environment('production'), отказ при непустой members без явного --fresh, --fresh только для БД из allowlist (izigo_test|izigo_bench)
- NEW Modules/Calculator/Support/Bench/QueryStatsCollector.php — агрегатор DB::listen: счётчик запросов, суммарное SQL-время, топ-N нормализованных statement; start/stop/report, без хранения полного лога
- NEW Modules/Calculator/Console/EngineBenchCommand.php — artisan `calculator:bench-engine {--sizes=1000,5000,20000} {--iterations=3} {--engine=v1,v2} {--fresh} {--json=} {--md=}`; на каждый размер: migrate:fresh → генерация дерева → warmup-прогон → измеряемые итерации; фазы V1: (a) NetworkRepository::load, (b) чистый CompensationEngine::calculate (read-only вызов ядра), (c) полный ActivationService::activate; V2: включение фиче-флагов через FeatureFlagService + markPaid-фикстура/пайплайн + StructureBonusRunCommand; вывод таблицей + JSON/MD
- EDIT Modules/Calculator/Providers/CalculatorServiceProvider.php — регистрация EngineBenchCommand в registerCommands() (строки ~140-150); в расписание НЕ добавлять
- READ-ONLY (не менять, только вызывать): Services/ActivationService.php, Repositories/EloquentNetworkRepository.php, Repositories/EloquentPlanRepository.php, Domain/CompensationEngine.php + Domain/*, V2/* (CalculatorV2ServiceProvider, PaidOrderV2PipelineImpl, шаги пайплайна, StructureBonusRunCommand), Services/Placement/* (спецификация инвариантов дерева), Services/OrderService.php (markPaid — референс V2-пути)
- NEW docs/reviews/2026-07-20-t3-engine-perf-benchmark.md — итоговый отчёт: методика, окружение/hardware, таблицы size×engine×{wall ms, SQL count, SQL time ms, peak MB}, разбивка фаз (load/engine/persist), топ горячих запросов, вердикт: нужна ли оптимизация, что горячее (ожидаемо — построчный persist снапшота в recompute), оправдан ли Octane — без внесения оптимизаций

### Общие файлы (риск конфликта)
- mh-calc-backend-main/Modules/Calculator/Providers/CalculatorServiceProvider.php (t2 — команда/расписание поллинга, t3 — регистрация bench-команды; единственный реально общий файл)
- mh-calc-backend-main/phpunit.xml — НЕ менять (исключение из CI достигается формой artisan-команды, не группами phpunit); упомянут как соблазн-точка
- .github/workflows/deploy.yml — НЕ менять (paths-ignore docs/** уже покрывает отчёт)

### Риски конфликтов
- CalculatorServiceProvider.php: t2 правит registerCommands/registerCommandSchedules в тех же строках (~142-171) — держать вставку bench-команды одной строкой в конце списка commands(), мерджить после t2 или тривиально разрешить конфликт
- Timestamp-префиксы миграций: t3 миграций НЕ создаёт — риска пересечения с миграцией t2 (payments.last_poll_result) нет по построению
- .env.example и Modules/Calculator/Config/config.php: t3 их НЕ трогает (zero-footprint) — конфликтов с t1/t2 нет; если в ходе ревью появится желание bench-toggle в конфиге, согласовать очередность с t2
- Routes/api.php, webApi.js, cors/sanctum: t3 не касается вообще — основная зона конфликтов t1/t2 обходится полностью
- docs/reviews/: только новый файл с уникальным датированным именем — конфликтов нет; push отчёта не триггерит deploy (paths-ignore docs/**)
- Феномен окружения, не git-конфликт: тяжёлый бенч и обычный тест-прогон делят один izigo-test-pg — гонять бенч в отдельной БД izigo_bench, чтобы не ронять параллельный php artisan test соседних задач

### Тест-план
- Unit SyntheticTreeGeneratorTest: точное число узлов; единственный корень; unique (parent_id,position) не нарушен; path каждого узла = path родителя + '.' + id (валидный ltree); sponsor_id всегда ссылается на участника с меньшим id; детерминизм — два прогона с одним seed дают идентичный checksum структуры
- Unit/Feature negative-cases guard'ов (обязательные — генератор пишет в БД массово): отказ при environment('production'); отказ при непустой таблице members без --fresh; отказ --fresh для БД вне allowlist (izigo_test|izigo_bench); все три — с assert'ом, что ни одна строка не записана
- Feature EngineBenchSmokeTest (лёгкий, идёт в обычный CI, ~50 узлов, 1 итерация): команда завершается кодом 0; JSON-вывод содержит wall_ms/sql_count/sql_time_ms/peak_mb по каждой фазе; после прогона инварианты данных целы — sum(member_earnings.total) сходится с суммой ledger-дельт, число MemberEarning ≤ числу участников (бенч гоняет реальный путь и не ломает семантику)
- Смок консистентности V1-фаз: чистый CompensationEngine::calculate на сгенерённом дереве даёт тот же суммарный доход, что полный activate() (защита от расхождения методики замера с реальным путём)
- V2-смок (за включёнными флагами): пайплайн-шаги отрабатывают на bench-дереве без исключений на маленьком N; при выключенных флагах V2-сценарий команды честно скипается с пометкой в выводе
- Контроль CI-бюджета: суммарное время новых тестов в php artisan test — секунды (тяжёлые размеры 1k/5k/20k только ручным запуском в докере против izigo-test-pg:5544); проверить локальным прогоном полного сьюта до/после
- Запретная зона: git diff по Modules/Calculator/Domain/** и Modules/Calculator/V2/** обязан быть пустым — чек в саморевью/PR-чеклист

### Вопросы к гейту
- Скоуп V2-замера: достаточно ли пост-оплатного пайплайна (VolumeCapture/Statuses/Referral/Awards в markPaid под флагами) + разовый StructureBonusRunCommand, или официальные числа нужны и по period-close командам (HalfMonthClose/MonthClose/LeadershipRun)? Дефолт плана — пайплайн + StructureBonusRun.
- Где снимаются «официальные» числа для решения по Octane: локальный докер izigo-php-dev на этой VM (дефолт; hardware фиксируется в отчёте) — или нужен прогон на prod-подобном ACA-контейнере? От этого зависит вес выводов про абсолютные миллисекунды.
- Достаточно ли верхней точки 20k участников, или добавить 50k как стресс-точку для экстраполяции роста (O-оценка тренда по 1k/5k/20k может быть сочтена достаточной)?

### Допущения
- Синтетическое дерево — почти полное бинарное с детерминированным (fixed seed) распределением пакетов (напр. 50/30/20 по package_id 1..3) и sponsor_id из числа предков; реальная форма прод-сети неизвестна — допущение фиксируется в отчёте. Инварианты соблюдаются: единственный корень (parent_id NULL), unique (parent_id, position), path = цепочка предков (ltree), id родителя < id ребёнка (на этом стоит EloquentNetworkRepository::load и порядок движка).
- Генерация — прямыми чанковыми bulk-insert (по ~1000 строк), БЕЗ прогона PlacementService на каждого участника (20k×(транзакция+локи+BFS) неприемлемо долго); PlacementService используется только как спецификация инвариантов. Генератор НЕ подключается к DatabaseSeeder/прод-сидерам.
- Замер V1 полного пути — через публичный ActivationService::activate() (recompute приватный): на каждую итерацию новый idempotency_key + смена package_id листового участника → полный пересчёт. Чистое ядро меряется отдельно read-only вызовом (new CompensationEngine($plan))->calculate($network) + отдельно EloquentNetworkRepository::load(); разница с полным activate() ≈ стоимость персиста (delete+create снапшота по одной строке — ожидаемый лидер по числу SQL: O(N) MemberBonusLine::create).
- V2 по умолчанию меряется как: (а) markPaid-путь на минимальной order-фикстуре с включёнными флагами mh_plan_v2_engine + mh_v2_volumes/statuses/referral/awards (шаги VolumeCaptureStep, StatusesStep, ReferralBonusStep, AwardsStep из CalculatorV2ServiceProvider) и (б) разовый прогон StructureBonusRunCommand на том же дереве; ядро V2 (V2/Domain, V2/Services) только вызывается.
- Бенчмарк гоняется в докере izigo-php-dev против izigo-test-pg (postgres:16-alpine, 127.0.0.1:5544), БД izigo_bench (или izigo_test) c migrate:fresh; в прод не ходит никогда — guard по environment('production') и allowlist имён БД.
- Никаких новых таблиц, миграций, конфиг-ключей и .env-переменных — zero-footprint обвязка: всё параметризуется опциями artisan-команды; ядро Domain/* и V2/* не изменяется ни на строку.
- Исключение из CI автоматическое: тяжёлый бенчмарк — artisan-команда, которую php artisan test (job test в deploy.yml) не запускает; в CI попадает только лёгкий smoke-тест (~50 узлов, секунды). Отчёт в docs/reviews/ не триггерит deploy (paths-ignore docs/**).
- Метрики: wall-time через hrtime(), число SQL и суммарное SQL-время через DB::listen с агрегатором (НЕ enableQueryLog — на 20k узлов лог запросов с bindings раздует память и исказит peak memory), пиковая память через memory_reset_peak_usage()+memory_get_peak_usage(true) на каждый прогон (PHP 8.3), топ-10 нормализованных statement по количеству/времени.
- Отчёт docs/reviews/2026-07-20-t3-engine-perf-benchmark.md пишется по результатам прогонов (команда умеет --json/--md вывод для вставки таблиц); рекомендация по Octane выводится из разбивки фаз: если доминирует SQL/персист — Octane не поможет и это фиксируется числами; оптимизации НЕ вносятся.

