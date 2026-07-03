# MAJOR-бэклог — /armada-волна (2026-07-03)

Источник: `docs/reviews/2026-07-02-commercial-launch-readiness-audit.md` (раздел MAJOR).
Блокеры уже в проде (см. `project-izigo-launch-readiness`). Эта волна закрывает MAJOR-хвост.
Scope согласован: G1–G5. Мандат: мерджить на зелёном CI (merge train последовательный).

**Отложено осознанно (вне волны):** перф у движка (recompute bulk-insert, BFS→ltree — про 10k+,
граница запретной зоны), php-fpm/Octane вместо `artisan serve`, бэкап-runbook + retention (ручной az),
Sanctum-токен в httpOnly cookie (приемлемый trade-off по аудиту).

## Задачи (плоский граф, одна волна)

| ID | Фокус | Основные файлы | Риск |
|----|-------|----------------|------|
| G1 | backend-security | logout Sanctum, max_age initData, C5-маскирование | низкий |
| G2 | frontend-hardening | Next.js CVE-bump, security-заголовки, webvisor off на админке | средний (CSP не должна ломать Mini App) |
| G3 | ops/CI | concurrency в deploy.yml, пост-деплой смоук, бот в пайплайн | низкий |
| G4 | TON-matching | курсор start_utime вместо окна-100, pollPending 1 HTTP/тик | средний (деньги) |
| G5 | payment-integrity | один pending-инвойс на заказ, защита лида с expired-платежом | средний (деньги, миграция) |

Жёсткая рамка на всю волну: ядро движка (`CompensationEngine`/`Domain`/`LedgerService`-математика)
не трогаем. Каждый фикс — своя ветка + PR, ревью Гейта 4, merge train с HTTP-смоуком живой ревизии.
