#!/usr/bin/env bash
# Смоук-приёмка фронта izigo (ТЗ 2026-07-09, критерии 1–5).
# Использование: scripts/smoke.sh [--expect-next <major>] [--image <tag>] [--skip-build]
# Собирает docker-образ из текущего каталога, поднимает контейнер и проверяет:
#   1) версия next в образе (мажор = --expect-next, по умолчанию 15)
#   2) маршруты /, /miniapp, /cabinet, /cabinet/tree, /admin/login → HTTP 200
#      и ноль SSR-ошибок в логах (Element type is invalid / unhandled / ⨯)
#   3) /tonconnect-manifest.json → 200 + JSON с url/name/iconUrl
#   4) host-роутинг middleware: admin-хост «/» → /admin; обычный хост «/admin» → /miniapp
#   5) GA-гигиена: /admin/login без googletagmanager|mc.yandex; /miniapp — с googletagmanager
#      (NEXT_PUBLIC_SERVER_PROD=true запечён в образ ARG-дефолтом)
set -euo pipefail
cd "$(dirname "$0")/.."

EXPECT_NEXT=15
IMAGE=izigo-fe-smoke:local
SKIP_BUILD=0
KEEP=0   # --keep: не гасить контейнер (для последующего scripts/browser-smoke.sh)
while [[ $# -gt 0 ]]; do
  case "$1" in
    --expect-next) EXPECT_NEXT="$2"; shift 2 ;;
    --image) IMAGE="$2"; shift 2 ;;
    --skip-build) SKIP_BUILD=1; shift ;;
    --keep) KEEP=1; shift ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

PORT=3123
NAME=izigo-fe-smoke
FAIL=0
say() { printf '%s\n' "$*"; }
check() { # check <название> <ok:0|1>
  if [[ "$2" == 0 ]]; then say "  ✅ $1"; else say "  ❌ $1"; FAIL=1; fi
}

say "— статический инвариант: CSSTransition только с nodeRef (React 19 без findDOMNode)"
bad_rtg=$(grep -rn '<CSSTransition' src --include='*.js*' | wc -l)
with_ref=$(grep -rn -A1 '<CSSTransition' src --include='*.js*' | grep -c 'nodeRef=' || true)
check "CSSTransition: $bad_rtg шт., с nodeRef первым пропом: $with_ref" "$([[ "$bad_rtg" == "$with_ref" ]] && echo 0 || echo 1)"

if [[ "$SKIP_BUILD" == 0 ]]; then
  say "— docker build ($IMAGE)…"
  docker build -q -t "$IMAGE" . >/dev/null
fi

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --rm --name "$NAME" -p 127.0.0.1:$PORT:3000 "$IMAGE" >/dev/null
[[ "$KEEP" == 0 ]] && trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
sleep 8

say "— критерий 1: версия next"
NEXT_MAJOR=$(docker exec "$NAME" node -e 'console.log(require("next/package.json").version)' 2>/dev/null | cut -d. -f1 || echo 0)
check "next мажор = $EXPECT_NEXT (фактически: $NEXT_MAJOR)" "$([[ "$NEXT_MAJOR" == "$EXPECT_NEXT" ]] && echo 0 || echo 1)"

say "— критерий 2: маршруты"
for p in / /miniapp /cabinet /cabinet/tree /admin/login; do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "http://127.0.0.1:$PORT$p")
  check "GET $p → $code" "$([[ "$code" == 200 ]] && echo 0 || echo 1)"
done
ssr_errs=$(docker logs "$NAME" 2>&1 | grep -ciE 'element type is invalid|unhandled|⨯' || true)
check "SSR-ошибок в логах: $ssr_errs" "$([[ "$ssr_errs" == 0 ]] && echo 0 || echo 1)"

say "— критерий 3: tonconnect-манифест"
man=$(curl -s --max-time 15 "http://127.0.0.1:$PORT/tonconnect-manifest.json" || true)
ok=$(printf '%s' "$man" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(0 if all(k in d for k in ("url","name","iconUrl")) else 1)' 2>/dev/null || echo 1)
check "манифест: JSON c url/name/iconUrl" "$ok"

say "— критерий 4: host-роутинг middleware"
admin_loc=$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 15 -H 'Host: admin.izigo.adarasoft.com' "http://127.0.0.1:$PORT/")
check "admin-хост / → редирект в /admin (got: ${admin_loc:-none})" "$([[ "$admin_loc" == */admin* ]] && echo 0 || echo 1)"
plain_loc=$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 15 -H 'Host: izigo.adarasoft.com' "http://127.0.0.1:$PORT/admin")
check "обычный хост /admin → редирект в /miniapp (got: ${plain_loc:-none})" "$([[ "$plain_loc" == */miniapp* ]] && echo 0 || echo 1)"

say "— критерий 5: GA-гигиена"
admin_html=$(curl -s --max-time 30 "http://127.0.0.1:$PORT/admin/login")
ga_admin=$(printf '%s' "$admin_html" | grep -ciE 'googletagmanager|mc\.yandex' || true)
check "/admin/login без GA/Метрики (вхождений: $ga_admin)" "$([[ "$ga_admin" == 0 ]] && echo 0 || echo 1)"
mini_html=$(curl -s --max-time 30 "http://127.0.0.1:$PORT/miniapp")
ga_mini=$(printf '%s' "$mini_html" | grep -c googletagmanager || true)
check "/miniapp с GA (вхождений: $ga_mini)" "$([[ "$ga_mini" -ge 1 ]] && echo 0 || echo 1)"

if [[ "$FAIL" == 0 ]]; then say "SMOKE: PASS"; else say "SMOKE: FAIL"; exit 1; fi
