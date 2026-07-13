#!/usr/bin/env bash
# =============================================================================
# ACA liveness/readiness/startup probes для ca-izigo-backend (RG rg-izigo-beta-neu).
# Вешает ТРИ пробы на targetPort бэка = 8080 (Dockerfile EXPOSE 8080,
# docker/start.sh `artisan serve --port 8080`):
#
#   • Startup   httpGet /up        periodSeconds 5  failureThreshold 30 (~150с)
#       — окно на migrate + сидеры + scheduler:heartbeat ДО того как artisan serve
#         откроет порт. Пока Startup не прошёл, Liveness/Readiness НЕ считаются, поэтому
#         долгий старт не крэш-лупит контейнер (см. docker/start.sh: migrate/seed/heartbeat).
#   • Liveness  httpGet /up        periodSeconds 30 failureThreshold 3
#       — «процесс жив», дёшево, БЕЗ БД. Намеренно НЕ /api/health: вставший планировщик
#         отдаёт /api/health 503, но контейнер убивать из-за этого нельзя (крэш-луп) —
#         это забота readiness, а не liveness.
#   • Readiness httpGet /api/health periodSeconds 15 failureThreshold 3
#       — БД + свежесть heartbeat планировщика; 503 выводит реплику из ротации ingress,
#         но Liveness держит контейнер живым (реплика вернётся, когда health позеленеет).
#
# ⚠️ ЗАПУСКАТЬ ТОЛЬКО ИЗ АВТОРИЗОВАННОЙ СЕССИИ ЧЕЛОВЕКА (`az login` под аккаунтом с
#    правами на подписку). Managed identity mh-central имеет на rg-izigo-beta-neu только
#    READ-роли (Reader / Monitoring Reader / Log Analytics Reader, без Remediator), поэтому
#    `containerapp update` (прод-write) из watchdog/агента НЕ выполнить — только человек.
#    Прод-действия — красная зона.
#
# Идемпотентно: az containerapp update --yaml - применяет merge-patch — повторный прогон
# с тем же спеком безопасен (перезаписывает блок probes теми же значениями). Изменение
# template создаёт новую ревизию — проверяем её health в конце.
#
# Референс путей/портов: docker/start.sh (migrate→seed→heartbeat→serve --port 8080),
# routes/web.php (/up), routes/api.php (/api/health), runbook ops/izigo-monitoring-setup.md.
# =============================================================================
set -euo pipefail

RG="rg-izigo-beta-neu"
APP="ca-izigo-backend"
PORT=8080

echo "▶ [человек] Текущий спек проб контейнера ${APP} (до правки):"
az containerapp show -n "$APP" -g "$RG" \
  --query "properties.template.containers[0].probes" -o json

echo
echo "▶ [человек] Применяю Startup + Liveness + Readiness пробы на порт ${PORT} (merge-patch)…"
# Имя контейнера в template должно совпадать с существующим, иначе merge создаст второй
# контейнер. У ca-izigo-backend контейнер называется так же, как app (ca-izigo-backend);
# сверь при расхождении: az containerapp show -n "$APP" -g "$RG" \
#   --query "properties.template.containers[].name" -o tsv
az containerapp update -n "$APP" -g "$RG" --yaml - <<YAML
properties:
  template:
    containers:
      - name: ${APP}
        probes:
          - type: Startup
            httpGet:
              path: /up
              port: ${PORT}
            periodSeconds: 5
            failureThreshold: 30
          - type: Liveness
            httpGet:
              path: /up
              port: ${PORT}
            periodSeconds: 30
            failureThreshold: 3
          - type: Readiness
            httpGet:
              path: /api/health
              port: ${PORT}
            periodSeconds: 15
            failureThreshold: 3
YAML

echo
echo "▶ [человек] Ревизии после правки (жди Healthy / Running=True на новой ревизии):"
az containerapp revision list -n "$APP" -g "$RG" \
  --query "[].{name:name, active:properties.active, health:properties.healthState, running:properties.runningState, created:properties.createdTime}" \
  -o table

echo
echo "✅ Пробы применены. Если новая ревизия НЕ доходит до Healthy (застряла на Startup) —"
echo "   проверь порт/пути и логи: az containerapp logs show -n ${APP} -g ${RG} --tail 100"
echo "   Откат: повторно прогнать этот скрипт с прежними/скорректированными значениями,"
echo "   либо снять пробы через az containerapp update --yaml - с пустым probes: []."
