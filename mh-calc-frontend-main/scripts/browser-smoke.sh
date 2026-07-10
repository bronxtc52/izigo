#!/usr/bin/env bash
# Обёртка браузерного смоука: гоняет scripts/browser-smoke.mjs в официальном Playwright-образе
# (Chromium уже внутри, на хост ничего не ставим). Контейнер фронта должен уже слушать
# 127.0.0.1:3123 (его поднимает scripts/smoke.sh, запускать с --keep либо руками).
# Использование: scripts/browser-smoke.sh [base_url]
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="${1:-http://127.0.0.1:3123}"
PW_IMAGE="mcr.microsoft.com/playwright:v1.57.0-jammy"

docker run --rm --network host \
  -v "$PWD/scripts/browser-smoke.mjs:/work/browser-smoke.mjs:ro" \
  -w /work -e SMOKE_BASE_URL="$BASE" "$PW_IMAGE" \
  bash -lc 'npm init -y >/dev/null 2>&1 && npm i --no-audit --no-fund playwright@1.57.0 >/dev/null 2>&1 && node browser-smoke.mjs'
