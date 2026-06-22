# Block C — Gate A (утверждённые решения)

Дата: 2026-06-22. Статус: утверждено на гейте A. Это **референс для кодеров** 7 фич
Блока C — единый источник контрактов и границ, чтобы волны кодились без рассинхрона.

Связанные документы:
- `docs/block-c-migration-ledger.md` — резерв таймстампов миграций + правило фронт-API.
- `plan.md` / `roadmap.md` — общий план Блока C (7 /armanda-циклов).
- Память: `mhg-mature-leader-source`, `izigo-adaptation-roadmap`.

Запретные зоны (не менять): `Modules/Calculator/Domain/**`,
`Modules/Calculator/Services/Bonus/**`, `Services/Payment`, `Services/Payout`,
`Services/Telegram` (auth), валютная логика. Валюта — только USD. Auth — только
Telegram (initData для Mini App, Login Widget→Sanctum для веб-админки).

## Решения гейта A (17 пунктов)

1. **Порядок волн.** Волна A = {C1 notifications, C3 feature_flags, C5 exports,
   C6 copartners} → Волна B = {C2 helpdesk, C7 monitoring} → Волна C = {C4 i18n}.
   (C7 зависит от outbox C1; C4 финализирует ключи после остальных фронт-фич.)

2. **Контракт C1 (ядро уведомлений):**
   `NotificationService::enqueueToMember(memberId, kind, html, ?title, ?dedupKey, ?data, inbox = true)`
   плюс батч-вариант `enqueueForMembers(memberIds, kind, html, ?title, ?dedupKey, ?data, inbox = true)`.

3. **Каналы доставки C1:** inbox (в приложении) + Telegram. `inbox = true` по
   умолчанию кладёт запись и в inbox партнёра.

4. **Событие MVP для C1:** статус выплаты (withdrawal status change). Остальные
   события — позже; в MVP только это триггерит уведомление.

5. **Рассылки (broadcasts):** доступны ролям **owner + support**.

6. **C2 helpdesk — транспорт:** polling каждые **5–8 секунд** (без websockets).

7. **C2 — упрощения:** без priority, без вложений. Только тикет + сообщения.

8. **C3 feature_flags — дефолт:** все флаги **заранее выключены**, **deny-by-default**.

9. **C3 — чтение флагов:** через **cabinet-auth** (telegram.auth). Управление —
   owner-only (web.admin + `calculator.role:owner`).

10. **C4 i18n — скоуп:** покрыть **все фронтовые ключи** переводами (az,kk,ky,mn,ru,uz).

11. **C4 — out of scope:** бэкенд-locales (серверные строки) в Блок C **не входят**.

12. **C5 exports — PII-режим:** полный режим — **маска по умолчанию** + **reveal
    только owner** + **аудит** каждого reveal.

13. **C5 — форматы экспорта:** **JSON + CSV**.

14. **C5 — что считаем PII:** `telegram_username`, `payout_details`, KYC-данные.

15. **C6 copartners — данные:** партнёр может завести **несколько записей**
    со-партнёров, **без валидации суммы**.

16. **C6 — админка:** **read-only** (админ только просматривает со-партнёров).

17. **C7 monitoring — скоуп:** просмотр **outbox** (из C1) + **failed_jobs**,
    **только owner**, read-only.

## RBAC-карта по фичам (для роутов)

| Фича | Партнёр (cabinet, telegram.auth) | Админ (web.admin + role) |
|------|----------------------------------|--------------------------|
| C1 notifications | inbox: свои уведомления | broadcasts: owner, support |
| C2 helpdesk | свои тикеты+сообщения | ответы: owner, support |
| C3 feature_flags | чтение активных флагов | управление: owner |
| C4 i18n | (фронт; бэк по необходимости) | оверрайды: owner |
| C5 exports | — | сводки: owner,finance,support; reveal/PII-экспорт: owner |
| C6 copartners | свои записи (CRUD без валидации суммы) | просмотр: owner,finance,support |
| C7 monitoring | — | owner-only, read-only |

> owner проходит всегда (RoleMiddleware). Negative-cases (unauthorized, чужой
> участник, истёкший токен, граница ролей) — обязательны в тестах фич с auth/PII.

## Анти-конфликт каркас (создан на этом гейте)

- Бэк: роуты разведены — `Modules/Calculator/Routes/api/<feature>.php` (7 стабов),
  подключены `require` в хвосте `Routes/api.php`. Каждая фича пишет роуты в своём стабе.
- Фронт админка: `src/views/admin/web/nav/registry.js` (`blockCSections`, пустой),
  подмешан в `WebAdminShell.js`. Фичи добавляют `<feature>.nav.js` + строку в registry.
- Фронт Mini App: `src/views/miniapp/tabs/registry.js` (`blockCTabs`/`blockCTabRender`,
  пустой), подмешан в `MiniAppShell.js`. Фичи C1/C2/C6 добавляют `<feature>.tab.js`.
- Фронт-API: webApi.js / miniapp api.js НЕ дробим — каждая фича дописывает вызовы в
  хвост файла внутри маркеров `// >>> Block C <feature>` … `// <<< Block C <feature>`.
- Миграции: диапазоны таймстампов в `docs/block-c-migration-ledger.md`.
