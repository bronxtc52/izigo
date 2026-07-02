#!/usr/bin/env bash
# =============================================================================
# Azure Monitor alert-правила для IziGo прод (RG rg-izigo-beta-neu). БЕЗ action
# group — все алёрты SILENT (без уведомлений), как у остальных наших проектов.
# Поверх алёртов кросс-проектный дайджест шлёт server-watchdog (см. runbook
# ops/izigo-monitoring-setup.md).
#
# ⚠️ ЗАПУСКАТЬ ИЗ АВТОРИЗОВАННОЙ СЕССИИ ЧЕЛОВЕКА (`az login` под аккаунтом с
#    правами на подписку). Managed identity mh-central НЕ видит rg-izigo-beta-neu,
#    поэтому из watchdog/агента это не выполнить — только человек из терминала.
#
# Стек: Laravel backend (artisan serve + schedule:work, single-replica),
#       Next.js frontend (Mini App), grammY bot (Node, без ingress, single-replica).
# Идемпотентно: `az monitor ... create` перезаписывает правило с тем же именем
# в том же RG (upsert), повторный прогон безопасен.
# =============================================================================
set -euo pipefail

SUB="c05debcb-f65a-4aee-9d1e-0f598536a024"
RG="rg-izigo-beta-neu"

# ⚠️ TODO(человек): подставь ТОЧНОЕ имя Log Analytics workspace RG rg-izigo-beta-neu.
# Узнать: az monitor log-analytics workspace list -g rg-izigo-beta-neu \
#           --query "[].name" -o tsv
# (это ресурс appLogsConfiguration ACA-окружения cae-izigo). Скрипт упадёт с явной
# ошибкой ниже, пока плейсхолдер не заменён — это защита от создания scheduled-query
# с несуществующим scope.
WS_NAME="${IZIGO_LAW_NAME:-REPLACE_ME_law-izigo-beta-neu}"

APP_BASE="/subscriptions/${SUB}/resourceGroups/${RG}/providers/Microsoft.App/containerapps"
WS="/subscriptions/${SUB}/resourceGroups/${RG}/providers/Microsoft.OperationalInsights/workspaces/${WS_NAME}"
TAGS="project=izigo env=beta owner=bronxtc52"

if [[ "$WS_NAME" == REPLACE_ME_* ]]; then
  echo "❌ WS_NAME не задан. Подставь имя Log Analytics workspace (см. TODO вверху)" >&2
  echo "   или запусти: IZIGO_LAW_NAME=<имя> bash ops/alerts-izigo.sh" >&2
  exit 1
fi

APPS=(ca-izigo-backend ca-izigo-frontend ca-izigo-bot)

# Always-on приложения (minReplicas >= 1) — только они получают no-replicas алёрт.
# scale-to-zero (min=0) сюда НЕ включать: 0 реплик в простое — норма, иначе ложный critical.
# backend (artisan serve + schedule:work) и bot — single-replica always-on. frontend Next —
# как правило min=1; ⚠️ ПРОВЕРЬ фактический scale перед прогоном:
#   az containerapp show -n <app> -g rg-izigo-beta-neu \
#     --query "properties.template.scale" -o json
# Если какое-то приложение реально scale-to-zero — убери его из ALWAYS_ON.
ALWAYS_ON=(ca-izigo-backend ca-izigo-frontend ca-izigo-bot)

# Пороги CPU/памяти = 80% от лимита КОНКРЕТНОГО контейнера (не фиксированное значение:
# фикс либо ложно срабатывает на мелком контейнере, либо не срабатывает на крупном —
# см. knowledge-base / комментарий в ../aidos-football/ops/alerts-draft.sh).
# ⚠️ Значения ниже — ПРЕДПОЛОЖЕНИЕ по типовому ACA-профилю. Сверь с фактическими лимитами:
#   az containerapp show -n <app> -g rg-izigo-beta-neu \
#     --query "properties.template.containers[0].resources" -o json
# и поправь, если лимиты другие.
declare -A CPU_NANO=(
  [ca-izigo-backend]=400000000    # 80% от 0.5 vCPU (500000000)
  [ca-izigo-frontend]=400000000   # 80% от 0.5 vCPU (500000000)
  [ca-izigo-bot]=200000000        # 80% от 0.25 vCPU (250000000)
)
declare -A MEM_BYTES=(
  [ca-izigo-backend]=858993459    # 80% от 1 GiB  (1073741824)
  [ca-izigo-frontend]=858993459   # 80% от 1 GiB  (1073741824)
  [ca-izigo-bot]=429496730        # 80% от 512 MiB (536870912)
)

for APP in "${APPS[@]}"; do
  SCOPE="${APP_BASE}/${APP}"

  # no-replicas — только для always-on приложений.
  if [[ " ${ALWAYS_ON[*]} " == *" ${APP} "* ]]; then
    az monitor metrics alert create -n "al-${APP}-no-replicas" -g "$RG" --scopes "$SCOPE" \
      --condition "min Replicas < 1" --window-size 5m --evaluation-frequency 1m --severity 1 \
      --description "[$APP] нет активных реплик >=5м" --tags $TAGS
  fi

  # RestartCount — КУМУЛЯТИВНЫЙ per-replica счётчик, не дельта. Агрегация `total`
  # суммирует один и тот же текущий счётчик по семплам окна (~3×) → ложно залипает в
  # Fired навсегда даже без новых рестартов. Берём `max` (реальный текущий счётчик
  # живой реплики) + auto-mitigate (новая реплика после деплоя = счётчик 0 → алёрт
  # сам закрывается). См. CLAUDE.md и knowledge-base/runbooks/aca-restartcount-alert-total-vs-max.md.
  az monitor metrics alert create -n "al-${APP}-restarts" -g "$RG" --scopes "$SCOPE" \
    --condition "max RestartCount > 3" --window-size 15m --evaluation-frequency 5m --severity 2 \
    --auto-mitigate true \
    --description "[$APP] реплика рестартовала >3 раз (crash-loop)" --tags $TAGS

  az monitor metrics alert create -n "al-${APP}-cpu-high" -g "$RG" --scopes "$SCOPE" \
    --condition "avg UsageNanoCores > ${CPU_NANO[$APP]}" --window-size 10m --evaluation-frequency 5m --severity 3 \
    --description "[$APP] CPU > 80% vCPU-лимита контейнера 10м" --tags $TAGS

  az monitor metrics alert create -n "al-${APP}-memory-high" -g "$RG" --scopes "$SCOPE" \
    --condition "avg WorkingSetBytes > ${MEM_BYTES[$APP]}" --window-size 10m --evaluation-frequency 5m --severity 3 \
    --description "[$APP] RSS > 80% лимита контейнера 10м" --tags $TAGS
done

# --- scheduled-query поверх Log Analytics workspace RG ---

# ACA system failures: hard-fail (RevisionFailed/ContainerFailed/Failed) сразу, либо
# probe-storm (>=10 ProbeFailed за окно) — чтобы одиночный флап не поднимал алёрт.
az monitor scheduled-query create -n "al-izigo-aca-system-failures" -g "$RG" \
  --scopes "$WS" --severity 1 --window-size 5m --evaluation-frequency 1m \
  --condition "count 'rows' > 0" \
  --condition-query rows="ContainerAppSystemLogs_CL | where TimeGenerated > ago(5m)
    | where ContainerAppName_s startswith 'ca-izigo-'
    | where Reason_s in~ ('ProbeFailed','RevisionFailed','ContainerFailed','Failed')
       or Log_s has_any ('Probe failed','Revision failed','Container failed','failed startup probe')
    | summarize total=count(), hard=countif(Reason_s in~ ('RevisionFailed','ContainerFailed','Failed') or Log_s has_any ('Revision failed','Container failed'))
    | where hard > 0 or total >= 10" \
  --description "ACA system failures (hard fail, или probe-storm >=10) по izigo apps" --tags $TAGS

# Console critical errors: и Laravel/PHP (fatal/exception), и Node/JS (bot/frontend).
az monitor scheduled-query create -n "al-izigo-console-critical-errors" -g "$RG" \
  --scopes "$WS" --severity 2 --window-size 5m --evaluation-frequency 5m \
  --condition "count 'rows' > 0" \
  --condition-query rows="ContainerAppConsoleLogs_CL | where TimeGenerated > ago(5m)
    | where ContainerAppName_s startswith 'ca-izigo-'
    | where Log_s has_any ('PHP Fatal','production.ERROR','Uncaught','Stack trace',
        'SQLSTATE','Unhandled','uncaughtException','unhandledRejection',
        'ECONNREFUSED','ETIMEDOUT','FATAL','TypeError','ReferenceError')" \
  --description "Critical console errors (php/node) по izigo apps" --tags $TAGS

# --- daily-cap 2 GB на Log Analytics workspace (как у остальных проектов) ---
# Ограничивает суточный объём приёма, чтобы залоговый шторм не разорил по деньгам.
az monitor log-analytics workspace update -g "$RG" --workspace-name "$WS_NAME" \
  --daily-quota-gb 2

echo "✅ izigo alert-правила + daily-cap созданы (silent, без уведомлений)."
echo "   Проверь: az monitor metrics alert list -g $RG -o table"
echo "            az monitor scheduled-query list -g $RG -o table"
