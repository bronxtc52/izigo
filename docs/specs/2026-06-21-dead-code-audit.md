# Аудит мёртвого / нерабочего / stub-кода — IziGo

Дата: 2026-06-21 · Режим: **audit-first** (удаляем только утверждённое) · Конвейер: /armanda
Scope: backend (Laravel), bot (Node), frontend (Next.js). Контекст: живая beta, середина Фаз 4–7 — часть заглушек намеренный каркас, сверено с `plan.md`/`roadmap.md`/`mlm_platform.md`.

Легенда вердиктов:
`[SAFE-DELETE]` мёртвый, удалять безопасно · `[FIX]` битый, чинить · `[KEEP-SCAFFOLD]` каркас будущей фазы, не трогать · `[KEEP]` нужный/boilerplate · `[REVIEW]` нужно решение владельца.

---

## BACKEND (Laravel, Modules/Calculator + Modules/ConfigIziGo)

### [SAFE-DELETE]
| # | Путь:строка | Что | Обоснование |
|---|---|---|---|
| B1 | `Modules/ConfigIziGo/Http/Controllers/TestController.php` (`dev1()`) + dev-роут `dev/test/dev1` в `routes/web.php` | Отладочный скретч с `dd()` на хардкод-массиве | Не функционал; за флагом `app.test_route_available`, но это dump-песочница |
| B2 | `Modules/Calculator/Services/CalculatorAuthService.php:32` (`logout()`) + docblock в `Facades/CalculatorAuth.php:13` | Метод legacy-аутентификации | 0 вызовов; `check()/token()/setToken()` используются, `logout()` — нет |
| B3 | `Modules/Calculator/Services/Bonus/BonusLeaderService.php:90,94` | Закомментированные `//dump(...)` | Мёртвые debug-дампы |
| B4 | `Modules/Calculator/Routes/api.php:57-67` | Закомментированный блок дублирующих structure-маршрутов | Дубль активных `node-details`/`index` (строки 48-53) |

### [FIX] — битый код
| # | Путь | Что | Почему чинить |
|---|---|---|---|
| B5 | `Modules/Calculator/Routes/web.php:17` `Route::resource('calculator', CalculatorController::class)` | Контроллер не реализует ни одного resource-метода (index/show/store/update/destroy) | 7 эндпоинтов дают 500/неопределённое поведение; убрать `Route::resource` (рудимент генератора nwidart) |
| B6 | `Modules/ConfigIziGo/routes/web.php` `Route::resource('configizigo', ...)` | Контроллер реализует только `getLocales()` | Те же 7 битых эндпоинтов; реально работает лишь `GET /locales` из `api.php` |
| B7 | Миграции `database/.../2024_12_17_074719_user_add_avatar.php` и `...074719_user_avatar_to_text.php` | Одинаковый timestamp у двух миграций | Недетерминированный порядок применения (сейчас спасает алфавит); переименовать вторую на поздний timestamp |

### [KEEP-SCAFFOLD — Фаза 4] — НЕ удалять
- `Modules/Calculator/Services/Payout/UsdtTonPayoutGateway.php:33,38` — `send()/status()` бросают `RuntimeException('TON payout driver не реализован')`. Default-биндинг PayoutGateway в проде: намеренная защита от случайной боевой выплаты до live-verify. Требует реализации перед включением выплат.
- `Modules/Calculator/Services/Payment/TonPayGateway.php:21` — TODO авто-экспирация (TTL) pending-платежей. Не ломает приём (poll-подтверждение работает).

### [KEEP — boilerplate]
- `database/seeders/DatabaseSeeder.php` (тело закомментировано) — стандартный Laravel-каркас.
- `database/factories/UserFactory.php` + `app/Models/User.php` — дефолтный Laravel-User (auth по факту Telegram-only), но стандартный каркас, удалять не стоит.

### [REVIEW] — нужно решение
- B8 `Modules/Calculator/Services/Bonus/BonusRankService.php:15` — `@TODO пересчитать в валюте отображения`. Проверить корректность валюты бонуса.
- B9 `Modules/Calculator/Database/Seeders/CalculatorDatabaseSeeder.php` — обёртка вызывает только `ProductSeeder`, но `docker/start.sh:13` сидит `ProductSeeder` напрямую → обёртка не используется. Удалить ИЛИ привязать start.sh к обёртке.

---

## FRONTEND (Next.js 14, JS) — мёртвый web-cabinet/admin (подтверждён `plan.md:574`)

Контекст: проект сознательно перешёл на «только Telegram Mini App»; `/cabinet` и `/admin` редиректят на `/miniapp` через layout, который возвращает `<RedirectToMiniApp/>` и не рендерит `{children}`. Живая поверхность — `MiniAppShell` → `MiniAppAdmin`.

### [SAFE-DELETE] — осиротевшие view старого web-кабинета/админки
| # | Файл | Обоснование |
|---|---|---|
| F1 | `src/views/cabinet/Dashboard.js` | импортируется только мёртвым `app/cabinet/page.js` |
| F2 | `src/views/cabinet/Profile.js` | то же (`app/cabinet/profile/page.js`) |
| F3 | `src/views/cabinet/RankProgress.js` | то же (`app/cabinet/rank/page.js`) |
| F4 | `src/views/cabinet/TeamTree.js` | то же (`app/cabinet/tree/page.js`); дерево теперь в Mini App |
| F5 | `src/views/cabinet/CabinetLayout.js` | 0 потребителей |
| F6 | `src/views/cabinet/api.js` | token-API web-кабинета; Mini App ходит через `views/miniapp/api.js` по initData |
| F7 | `src/views/admin/AdminLayout.js` | 0 потребителей (сайдбар-каркас старой web-админки) |

### [REVIEW — важно] — page.js под redirect-layout
| # | Файлы | Нюанс |
|---|---|---|
| F8 | `src/app/cabinet/page.js`, `cabinet/profile/page.js`, `cabinet/rank/page.js`, `cabinet/tree/page.js`, `src/app/admin/page.js`, `admin/plan/page.js`, `admin/members/[id]/page.js` | Контент недостижим (layout не рендерит children), но **сам page.js нужен, иначе маршрут отдаст 404 вместо редиректа** — а smoke-тест `plan.md:666` ждёт редирект. Варианты: (a) свести каждый page.js к минимальному стабу `export default function(){return null}` (убрать импорт мёртвых view F1–F7); (b) удалить page.js и принять 404 вместо редиректа. Нужно решение. |

### [FIX]
- F9 `src/views/calculator/components/changePackage/ChangePackage.js:7` — удалить мёртвый закомментированный импорт `// import { withoutPackageOption } ...`.

### [KEEP — редирект-гейт, по плану]
- `src/app/cabinet/layout.js`, `src/app/admin/layout.js`, `src/views/miniapp/RedirectToMiniApp.js` — намеренный редирект (smoke-тест ждёт его).

### [KEEP-SCAFFOLD — Фаза 4] — НЕ удалять
- `src/views/miniapp/tonPay.js` (узлы `NEEDS-LIVE-VERIFY`, контрольный платёж) — будущая фаза.

### [REVIEW]
- F10 `src/views/auth/IziGoLogo.js` — 0 потребителей; вероятно остаток `/auth/*` (удалён по `plan.md:567`). Удалить, если бренд-ассет не нужен.
- F11 `@ton/crypto` (`package.json:14`) — единственная реально неиспользуемая npm-зависимость (`tonPay.js` тянет `@ton/ton`+`@ton/core`). Вероятный задел live-verify оплаты F4 — держать до прояснения.

> Ложные срабатывания `knip` (без `knip.json` не подхватывает alias `@/*` и dynamic-import) — НЕ трогать: все admin-views, используемые `MiniAppAdmin` (`MembersList/MemberCard/PlanSettings/AdminWithdrawals/initDataApi/admin/api.js`), весь живой calculator/miniapp/widgets/common граф, все «unused» зависимости кроме `@ton/crypto`.

---

## BOT (Node, grammY) — чисто

Все 5 файлов (`index/bot/messages/config/sentry.js`) связаны: команды `start/app/help` живые, экспорты используются, заглушек/TODO/мёртвых хендлеров нет.

### [REVIEW] — косметика
- BT1 `src/config.js:22` `export async function getSecret` — экспортируется, но используется только внутри файла (`loadBotToken`/`loadSentryDsn`). Можно убрать `export`. Не баг.

---

## Сводка

| Вердикт | Backend | Frontend | Bot | Итого |
|---|---|---|---|---|
| [SAFE-DELETE] | 4 (B1–B4) | 7 файлов (F1–F7) | 0 | 11 |
| [FIX] | 3 (B5–B7) | 1 (F9) | 0 | 4 |
| [KEEP-SCAFFOLD] | 2 | 1 | 0 | 3 |
| [KEEP/boilerplate] | 2 | 3 | 0 | 5 |
| [REVIEW] | 2 (B8–B9) | 3 (F8, F10, F11) | 1 (BT1) | 6 |

**Приоритет к действию:** F8 (битый редирект-маршрут vs 404), B5/B6 (битые `Route::resource`), B7 (дубль-timestamp миграций).
**НЕ трогать:** UsdtTonPayoutGateway, TonPayGateway, tonPay.js, redirect-layout'ы — это каркас Фазы 4 / намеренный гейт.

---

## Следующий шаг
Удаление/починка — только по утверждению этого отчёта, атомарными коммитами в рабочей ветке. До решения по [REVIEW] (особенно F8) page.js не трогаем.

---

## РЕЗОЛЮЦИЯ (исполнено 2026-06-22, ветка chore/phase-0-foundation)

**Backend:**
- ✅ B1 — `TestController.php` удалён; dev-роут и флаг `app.test_route_available` убраны.
- ✅ B2 — `CalculatorAuthService::logout()` + строка фасада удалены.
- ✅ B3 — мёртвые `//print`/`//dump` и закомментированный блок в `BonusLeaderService` вычищены.
- ✅ B4 — закомментированный structure-блок в `Routes/api.php` удалён.
- ✅ B5/B6 — битые `Route::resource` (calculator, configizigo) убраны.
- ⏭ B7 — **НЕ применяли**: Laravel сортирует миграции по полному имени файла, при равном timestamp порядок `add_avatar`<`avatar_to_text` детерминирован и корректен; переименование применённой миграции вызвало бы повторный прогон на деплое (migrate в start.sh) без пользы. Латентного бага в реальности нет.
- ✅ B9 — `CalculatorDatabaseSeeder` (неиспользуемая обёртка) удалён.
- 🔎 B8 — `BonusRankService` @TODO валюта: НЕ дед-код, а вопрос корректности расчёта → отдельная задача-расследование, в чистку не входит.
- ✅ KEEP — UsdtTonPayoutGateway, TonPayGateway, root DatabaseSeeder, UserFactory/User: не тронуты (каркас/boilerplate).

**Frontend:**
- ✅ F1–F7 — удалены 7 файлов мёртвого web-cabinet/admin (cabinet/* + admin/AdminLayout). Каталог `views/cabinet/` исчез.
- ✅ F8 — 7 page.js сведены к редирект-стабам (`export default () => null`): маршрут сохранён → редирект в layout, не 404.
- ✅ F9 — мёртвый закомментированный импорт в `ChangePackage.js` убран.
- ✅ F10 — `views/auth/IziGoLogo.js` (0 ссылок) удалён, каталог `auth/` исчез.
- ⏭ F11 — `@ton/crypto`: **оставлен** (вероятный задел live-verify оплаты Ф4).
- ✅ KEEP — redirect-layout'ы, RedirectToMiniApp, tonPay.js, все admin-view живого MiniAppAdmin: не тронуты.

**Bot:**
- ✅ BT1 — лишний `export` у `getSecret` убран (используется только внутри файла).

**Проверки:** PHP `php -l` по всем изменённым файлам — чисто; `next lint` — только прежние warning'и, висящих импортов нет; тесты на удалённые символы не ссылаются. Итог diff: ~−700 строк.
**НЕ выполнено:** прогон phpunit (требует Postgres `izigo_test`) — предложить отдельно.
