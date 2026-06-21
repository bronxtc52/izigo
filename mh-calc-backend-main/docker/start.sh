#!/usr/bin/env sh
set -e

# Кэш конфига/роутов для прода
php artisan config:cache || true
php artisan route:cache || true

# Миграции на старте (Фаза 0, single-replica). Для multi-replica — вынести в ACA Job.
php artisan migrate --force || true

# Разовый сброс схемы под Telegram-only auth (beta-данные сбрасываем). Правки внесены
# в уже применённые миграции, поэтому на старом проде они не подхватываются обычным
# migrate. Гард БЕЗОПАСЕН: migrate:fresh запускается ТОЛЬКО при позитивном обнаружении
# СТАРОЙ таблицы role_user (есть лишь в до-рефакторинговой схеме). Если БД недоступна
# или схема уже новая — fresh НЕ выполняется (без случайного сноса данных). После
# первого сброса role_user исчезает → гард самоотключается. TODO: удалить после миграции.
SCHEMA_MARK=$(php artisan tinker --execute="echo Schema::hasTable('role_user') ? 'OLD_SCHEMA' : 'NEW_SCHEMA';" 2>/dev/null || true)
case "$SCHEMA_MARK" in
  *OLD_SCHEMA*)
    echo "[start] role_user найдена → migrate:fresh (Telegram-only schema reset, beta)"
    php artisan migrate:fresh --force || true
    ;;
esac

# ACA подаёт порт через $PORT; дефолт 8080
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
