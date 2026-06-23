# Block C — Release Summary (для prod-гейта)

Ветка: `release/block-c` → цель мерджа: `main` (= авто-деплой на ACA). Дата: 2026-06-22.
Объём: 10 коммитов, 94 файла, +8398/−71. Прод НЕ затронут до мерджа.

## Что входит (7 фич + scaffold)
| Цикл | Фича | Коммит |
|---|---|---|
| scaffold | ветка, ledger, gate-A doc, разводка роутов/nav | `98fef27`,`c1dcdb9` |
| C3 | feature-flags (рантайм-тоглы + админ) | `0d56984` |
| C1 | уведомления + рассылки + outbox-диспетчер (backbone) | `ab230e0` |
| C5 | экспорт участника + PII маска/reveal/аудит (fail-closed) | `03c0879`,`fd17163` |
| C6 | совладельцы/наследники в профиле (инфо) | `a59aaec` |
| C2 | helpdesk (тикеты, чат, пуши через C1) | `be4659d` |
| C7 | read-only мониторинг outbox/планировщика | `334b9aa` |
| C4 | редактируемые переводы (i18n-оверрайды) | `e9624b7` |
| gating | показ фич по флагам (deny-by-default) | `daa66a3` |

## Миграции (8 новых таблиц — все аддитивные, без destructive)
`notification_broadcasts`, `notification_outbox`, `notification_inbox` (C1);
`feature_flags` (C3); `member_copartners` (C6); `translation_overrides` (C4);
`tickets`, `ticket_messages` (C2). C5/C7 — без миграций.
Применяются на старте контейнера `php artisan migrate --force` (docker/start.sh).

## Изменения окружения/инфры
- `docker/start.sh`: +`FeatureFlagSeeder` (идемпотентно, firstOrCreate — не перетирает выставленное).
- Новая scheduled-команда `notifications:outbox-dispatch` (everyMinute, withoutOverlapping) — крутится уже работающим `schedule:work`. Отдельного воркера не нужно (очередь = sync).
- **Новых env/секретов НЕТ.** Уведомления используют существующие `calculator.telegram_notify_enabled` + `telegram_bot_token` (Key Vault).

## Проверки (hardening — всё зелёное)
- Полный backend-suite: **302 passed**; падает только пред-существующий `StructureTest` (16, баг `email`, память `izigo-test-db-email-bug`) — не из Блока C.
- `route:list`: 30 роутов Блока C сосуществуют, 0 ошибок.
- Фронт `next build`: успешно (26/26 страниц).
- `git diff` против запретных зон (`Domain`, `Services/Bonus`, `Payment`, `Payout`, Telegram-auth) — **пусто**. Валюта/auth/движок не тронуты.

## Поведение на деплое (важно)
**Все фиче-флаги засеяны OFF (deny-by-default).** После деплоя новые фичи СКРЫТЫ (и в админке, и в Mini App) — ничего не меняется для пользователей, пока owner не включит флаг в админке («Фиче-флаги»). Раскатывать по одной: `c1_notifications`, `c2_helpdesk`, `c4_i18n_admin`, `c5_pii_export`, `c6_copartners`, `c7_jobs_monitor`. Гейтирование фронтовое + graceful (сбой чтения флагов → фича скрыта).

## Риски и rollback
- Риск низкий: миграции аддитивные (CREATE TABLE), фичи скрыты флагами, запретные зоны не тронуты, payout-хук уведомлений best-effort/после-commit (выплату не ломает — тест подтверждает).
- Rollback: `git revert` мерджа (фичи исчезнут; таблицы остаются пустыми и безвредными, при желании drop отдельной forward-миграцией). Флаги-OFF дают мгновенный «выключатель» без отката кода.

## Что ОСТАЁТСЯ за человеком (prod-гейт)
Мердж `release/block-c → main` (= прод-деплой + миграции на боевой БД) и последующее включение флагов — действие владельца.
