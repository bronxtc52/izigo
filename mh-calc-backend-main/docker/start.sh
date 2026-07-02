#!/usr/bin/env sh
set -e

# P1-hardening (O1): БЕЗ `|| true` — упавшая команда роняет старт контейнера, чтобы ACA
# не переключал трафик на ревизию с битым конфигом/схемой. Все сидеры идемпотентны
# (updateOrCreate / firstOrCreate / early-return) и без внешних зависимостей.

# Кэш конфига/роутов для прода. Несобираемый роут/конфиг ловится ещё в CI
# (route:cache-smoke в job test), здесь — последний рубеж.
php artisan config:cache
php artisan route:cache

# Миграции на старте (Фаза 0, single-replica). Для multi-replica — вынести в ACA Job.
# Упавшая миграция = упавший старт = трафик остаётся на старой ревизии.
php artisan migrate --force

# Стартовый каталог тарифов-товаров (Фаза 4). Идемпотентно по sku (updateOrCreate),
# поэтому безопасно при каждом старте; без этого «Магазин» пуст.
php artisan db:seed --class="Modules\\Calculator\\Database\\Seeders\\ProductSeeder" --force

# Block C (C3): начальный набор фиче-флагов, все выключены (deny-by-default).
# Идемпотентно (firstOrCreate) — не перетирает выставленное администратором значение.
php artisan db:seed --class="Modules\\Calculator\\Database\\Seeders\\FeatureFlagSeeder" --force

# Реальный текст Пользовательского соглашения (RU+EN). Идемпотентно: версия бампается только
# при изменении текста, иначе деплой не форсит повторное принятие у всех участников.
php artisan db:seed --class="Modules\\Calculator\\Database\\Seeders\\AgreementSeeder" --force

# Планировщик Laravel (single-replica). schedule:work раз в минуту вызывает schedule:run,
# который и дёргает commerce:tonpay-poll (приём ждёт подтверждения сети), commerce:autoship-run
# и commerce:payouts-poll. Без него эти команды на ACA не срабатывают. Фоном, рядом с serve;
# для multi-replica — вынести в отдельный ACA Cron Job (тогда убрать отсюда).
php artisan schedule:work &

# ACA подаёт порт через $PORT; дефолт 8080
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
