#!/usr/bin/env sh
set -e

# Кэш конфига/роутов для прода
php artisan config:cache || true
php artisan route:cache || true

# Миграции на старте (Фаза 0, single-replica). Для multi-replica — вынести в ACA Job.
php artisan migrate --force || true

# ACA подаёт порт через $PORT; дефолт 8080
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
