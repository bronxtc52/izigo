# ТЗ: миграция Laravel 10 → 12 (mh-calc-backend-main)

**Дата:** 2026-07-13 · **Класс:** L · **Конвейер:** /armanda v2.2.0 · **Ветка:** `chore/laravel-12`
**Разведка-источник:** субагент 2026-07-13 (в истории сессии) + [[env-example-is-ci-runtime]]

## Цель
Закрыть 3 dependabot-advisories `laravel/framework` (Signed URL path confusion, CRLF в email-правиле ×2).
Патч только в 12.60/12.61 — в 10.x/11.x бэкпорта нет → нужен мажор 10→12. Реальная attack-surface CVE
в приложении ≈ ноль (правило `email` не используется, auth Telegram-only), но dependabot флагует саму
зависимость; закрыть можно только апгрейдом.

## Scope (что делаем)
- `laravel/framework` ^10→^12 (через 11), `nwidart/laravel-modules` ^10→^12, `laravel/sanctum` ^3→^4,
  `spatie/laravel-data` 4.10.1→^4.11, dev: `phpunit` ^10→^11, `nunomaduro/collision` ^7→^8.
- `require.php` ^8.1→^8.2 (прод PHP уже 8.3).
- Реконсиляция `config/modules.php` (nwidart v12) и `config/sanctum.php` (Sanctum 4), `phpunit.xml` (схема 11).
- **scribe (knuckleswtf/scribe): СНЯТЬ из прод-сборки** (dev-only API-доки; мажор scribe 5 не должен
  блокировать L12 — убрать из require-dev или выключить генерацию).

## Границы (что НЕ делаем)
- **Логику движка `Modules/Calculator` (CompensationEngine V1) и `Modules/Calculator/V2` НЕ трогаем** —
  апгрейд касается их только через API Eloquent/Collection. Доказательство некасания = зелёный
  `tests/Verification/` (golden-регресс 4 бонусов) после КАЖДОГО шага.
- **Carbon остаётся v2** (L11/12 не форсят Carbon 3 — не брать, лишний breaking без выгоды).
- Не трогаем: валюту USD, Telegram-auth/платежи, скелет приложения (L11-скелет не форсится —
  legacy Kernel-бутстрап поддержан), уже-совместимые пакеты (sentry, laravel-enum, php-structure-discoverer).

## План (поэтапно, НЕ сразу→12; одна ветка, PR в main ОДИН в конце — merge=прод-деплой)
- **Шаг 0** — зелёный baseline: полный сьют (docker `izigo-php-dev` + `izigo-test-pg` :5544 `izigo_test`) = оракул.
- **Шаг 1 (→11)** — framework^11 + nwidart^11 + sanctum^4 + laravel-data^4.11 + phpunit^11/collision^8,
  php^8.2, снять scribe, реконсиляция config. Сьют зелёный.
- **Шаг 2 (→12)** — framework^12 + nwidart^12. Сьют зелёный + HTTP-смоук /up,/api/health.
- **Шаг 3** — `composer audit`=0 laravel-advisories, negative auth-тесты Sanctum, golden-регресс, фронт lint+build.

## Критерии приёмки (да/нет)
1. `composer audit` не показывает ни одного `laravel/framework` advisory (0 из прежних 3).
2. `laravel/framework` в composer.lock = ^12.61.
3. Полный сьют `php artisan test` зелёный (≥927 passed), в т.ч. под CI-условием `cp .env.example .env`.
4. `tests/Verification/` (golden-регресс движка V1/V2) зелёный — движок не задет.
5. Sanctum 4: negative auth-тесты веб-админки зелёные (без токена→401, чужой юзер→403, истёкший→401, logout→401).
6. Фронт `npm run lint && npm run build` зелёный (не задет, но CI-джоб `test` его гоняет).
7. `knuckleswtf/scribe` не в прод-зависимостях (снят/выключен), сборка не требует scribe 5.
8. CI-джоб `test` на PR зелёный.
9. Движок логика (`Modules/Calculator` ядро, `Modules/Calculator/V2`) в диффе не изменена (только при
   вынужденной API-правке — с обоснованием; golden-регресс покрывает).

## DoD / сдача
Один PR `chore/laravel-12` → main, CI-джоб `test` зелёный. **Мердж = прод-деплой (ACA) — только с явного
«ок» владельца.** Останавливаюсь на зелёном PR.
