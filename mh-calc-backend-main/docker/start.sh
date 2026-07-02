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

# B-5: seed heartbeat планировщика ДО его запуска, чтобы /api/health был зелёным ещё до
# первого тика schedule:run (иначе первые ~60с readiness-проба видела бы «no-heartbeat»).
php artisan scheduler:heartbeat

# Планировщик Laravel (single-replica). schedule:work раз в минуту вызывает schedule:run,
# который и дёргает commerce:tonpay-poll (приём ждёт подтверждения сети), commerce:autoship-run,
# commerce:payouts-poll, notifications:outbox-dispatch, leads:expire и scheduler:heartbeat.
# Без него эти команды на ACA не срабатывают. Фоном, рядом с serve; для multi-replica —
# вынести в отдельный ACA Cron Job (тогда убрать отсюда).
#
# B-5: СУПЕРВИЗИЯ. Раньше `schedule:work &` падал тихо (OOM/исключение) — контейнер оставался
# Healthy, а денежные кроны молча вставали. Теперь оборачиваем в перезапускающий цикл:
# смерть планировщика самовосстанавливается за 2с и логируется. Плюс страховка heartbeat/
# health: если и цикл не поднимет планировщик, метка протухнет → /api/health отдаст 503.
# `|| true` здесь — НЕ подавление ошибки миграции (её оставляем fail-fast), а защита цикла
# от `set -e`: ненулевой выход schedule:work не должен убить сам перезапускающий цикл.
while true; do
    php artisan schedule:work || true
    echo "[start.sh] scheduler (schedule:work) exited, restarting in 2s" >&2
    sleep 2
done &

# ACA подаёт порт через $PORT; дефолт 8080
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
