#!/usr/bin/env bash
# Ночной logical-бэкап прод-БД IziGo (izigo-pg-beta) на mh-central.
# Кросс-регионная страховка поверх PITR: PG в northeurope, дампы на диске VM в westeurope.
# Установка: cron 30 3 * * * из рабочей копии репо (процедуры — ops/izigo-pg-backup-restore.md).
# Требует: az с доступом к KV (MSI mh-central подходит), pg_dump >= версии сервера (16).
# При провале шлёт алёрт в Telegram (канал server-watchdog) — email на mh-central мёртв by design.
set -euo pipefail

VAULT=kv-bronxtc-dev
SECRET=izigo--beta--DB-PASSWORD
PGHOST=izigo-pg-beta.postgres.database.azure.com
PGUSER=izigo
PGDATABASE=izigo
BACKUP_DIR="${IZIGO_BACKUP_DIR:-$HOME/backups/izigo-pg}"
RETENTION_DAYS="${IZIGO_BACKUP_RETENTION_DAYS:-30}"

notify_failure() {
  local msg="🔴 izigo: ночной PG-бэкап УПАЛ на mh-central (см. ~/backups/izigo-pg/backup.log)"
  local token chat
  token=$(az keyvault secret show --vault-name "$VAULT" \
    --name server-watchdog--production--TELEGRAM-BOT-TOKEN --query value -o tsv) || return 0
  chat=$(az keyvault secret show --vault-name "$VAULT" \
    --name server-watchdog--production--TELEGRAM-CHAT-ID --query value -o tsv) || return 0
  curl -sS -m 15 "https://api.telegram.org/bot${token}/sendMessage" \
    -d chat_id="$chat" --data-urlencode text="$msg" >/dev/null || true
}
trap 'notify_failure' ERR

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

stamp=$(date -u +%Y%m%d-%H%M%S)
out="$BACKUP_DIR/izigo-$stamp.dump"

export PGPASSWORD
PGPASSWORD=$(az keyvault secret show --vault-name "$VAULT" --name "$SECRET" --query value -o tsv)

# --no-owner/--no-privileges: azure-роли (azure_pg_admin и т.п.) не существуют вне Azure —
# дамп должен восстанавливаться в чистый PG без ошибок владения.
# Пишем в .part и переименовываем только после верификации: усечённый обрыв не должен
# выглядеть валидным бэкапом (мониторинг ориентируется на наличие свежего .dump).
pg_dump "host=$PGHOST port=5432 dbname=$PGDATABASE user=$PGUSER sslmode=require" \
  -Fc --no-owner --no-privileges -f "$out.part"
unset PGPASSWORD

pg_restore --list "$out.part" >/dev/null
mv "$out.part" "$out"

find "$BACKUP_DIR" -maxdepth 1 -name 'izigo-*.dump' -type f -mtime +"$RETENTION_DAYS" -delete
find "$BACKUP_DIR" -maxdepth 1 -name 'izigo-*.dump.part' -type f -mtime +1 -delete

echo "[$(date -u +%FT%TZ)] OK $out ($(du -h "$out" | cut -f1))"
