#!/usr/bin/env bash
# =============================================================================
# ACA startup/liveness/readiness probes для ca-izigo-backend (RG rg-izigo-beta-neu).
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
#    READ-роли — `containerapp update` (прод-write) из watchdog/агента НЕ выполнить.
#    Прод-действия — красная зона; single-revision mode → новая ревизия сразу берёт 100%.
#
# БЕЗОПАСНОСТЬ ПОЛЯ: применяем ПОЛНЫЙ спек (az show -o yaml → впрыск probes → update --yaml),
# а НЕ минимальный merge-patch — так гарантированно НЕ теряются image/env/secretRef/resources
# контейнера. Идемпотентно: существующий блок probes снимается и переписывается заново.
#
# Референс путей/портов: docker/start.sh (migrate→seed→heartbeat→serve --port 8080),
# routes/web.php (/up), routes/api.php (/api/health), runbook ops/izigo-monitoring-setup.md.
# =============================================================================
set -euo pipefail

RG="rg-izigo-beta-neu"
APP="ca-izigo-backend"
PORT=8080
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "▶ [человек] Активная ревизия ДО правки (baseline для отката):"
az containerapp revision list -n "$APP" -g "$RG" \
  --query "[?properties.active].{name:name, health:properties.healthState, running:properties.runningState, traffic:properties.trafficWeight}" \
  -o table

echo
echo "▶ [человек] Текущие пробы контейнера ${APP} (до правки):"
az containerapp show -n "$APP" -g "$RG" \
  --query "properties.template.containers[0].probes" -o json

echo
echo "▶ [человек] Выгружаю полный спек и впрыскиваю пробы на порт ${PORT}…"
az containerapp show -n "$APP" -g "$RG" -o yaml > "$TMP/aca.yaml"

# Впрыск через python (без зависимости от yq/pyyaml): по имени контейнера, идемпотентно —
# снимаем существующий 6-отступный блок probes из контейнера, затем вставляем свежий.
APP="$APP" PORT="$PORT" python3 - "$TMP/aca.yaml" "$TMP/aca-probes.yaml" <<'PY'
import os, sys
src, dst = sys.argv[1], sys.argv[2]
app, port = os.environ["APP"], os.environ["PORT"]
lines = open(src).read().split("\n")

probes = f"""      probes:
      - type: Startup
        httpGet:
          path: /up
          port: {port}
        periodSeconds: 5
        failureThreshold: 30
      - type: Liveness
        httpGet:
          path: /up
          port: {port}
        periodSeconds: 30
        failureThreshold: 3
      - type: Readiness
        httpGet:
          path: /api/health
          port: {port}
        periodSeconds: 15
        failureThreshold: 3""".split("\n")

# 1) снять существующий блок probes (ключ на отступе 6 внутри контейнера) — идемпотентность.
def strip_probes(ls):
    # Снять блок probes контейнера: ключ "      probes:" (6 пробелов) + его тело —
    # проб-элементы "      - " (6 пробелов + дефис) и вложенные ключи "        " (8+),
    # до следующего 6-отступного ключа контейнера (resources/name/image/env…).
    out, i = [], 0
    while i < len(ls):
        if ls[i] == "      probes:":
            i += 1
            while i < len(ls) and (ls[i].startswith("      - ")
                                   or ls[i].startswith("        ")
                                   or ls[i].strip() == ""):
                i += 1
            continue
        out.append(ls[i]); i += 1
    return out

lines = strip_probes(lines)

# 2) вставить свежий блок сразу после строки имени НУЖНОГО контейнера (отступ 6, без дефиса).
name_line = f"      name: {app}"
out, injected = [], False
for ln in lines:
    out.append(ln)
    if not injected and ln == name_line:
        out.extend(probes); injected = True
if not injected:
    sys.stderr.write(f"FATAL: не найдена строка контейнера '{name_line}' — спек не изменён.\n")
    sys.exit(2)
open(dst, "w").write("\n".join(out))
print("  ✓ probes впрыснуты в контейнер", app)
PY

echo
echo "▶ [человек] Применяю полный спек (update --yaml, создаётся новая ревизия)…"
az containerapp update -n "$APP" -g "$RG" --yaml "$TMP/aca-probes.yaml" \
  --query "{newRev:properties.latestRevisionName, prov:properties.provisioningState}" -o json

echo
echo "▶ [человек] Ревизии после правки — ЖДИ Healthy / RunningAtMaxScale на новой ревизии:"
az containerapp revision list -n "$APP" -g "$RG" \
  --query "[].{name:name, active:properties.active, health:properties.healthState, running:properties.runningState, traffic:properties.trafficWeight, created:properties.createdTime}" \
  -o table

echo
echo "✅ Пробы применены полным спеком (env/image/secretRef сохранены)."
echo "   Смоук:   curl -fsS https://ca-izigo-backend.livelycoast-2b4dcf83.northeurope.azurecontainerapps.io/up"
echo "            curl -fsS .../api/health"
echo "   Если новая ревизия НЕ доходит до Healthy — логи:"
echo "     az containerapp logs show -n ${APP} -g ${RG} --tail 100"
echo "   ОТКАТ на предыдущую здоровую ревизию (подставь имя из списка выше, напр. ...--0000063):"
echo "     az containerapp revision set-active -n ${APP} -g ${RG} --revision <prev-healthy-revision>"
