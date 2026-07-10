// Браузерный смоук (ТЗ 2026-07-09, must-fix совета product_risk-1): headless-Chromium
// против работающего контейнера фронта. Ловит runtime-ошибки React/JS, которые curl не видит.
// Запуск — через официальный Playwright-образ (scripts/browser-smoke.sh), НЕ на хосте.
// Критерии на каждом маршруте: страница загрузилась, console.error/pageerror = 0
// (кроме известного шума), контент отрисован; /miniapp запрашивает tonconnect-манифест
// или монтирует TonConnect; /admin/login содержит поле ввода.
import { chromium } from 'playwright';

const BASE = process.env.SMOKE_BASE_URL || 'http://127.0.0.1:3123';
const ROUTES = ['/', '/miniapp', '/cabinet', '/cabinet/tree', '/admin/login'];
// Шум, не являющийся регрессией приложения: блокировки внешней аналитики/шрифтов в headless,
// отказ Telegram WebApp API вне Telegram, сетевые отказы до бэкенда (его в смоуке нет).
const IGNORE = [
  /googletagmanager|google-analytics|mc\.yandex/i,
  /net::ERR_(NAME_NOT_RESOLVED|CONNECTION_REFUSED|INTERNET_DISCONNECTED|ABORTED)/i,
  /Failed to load resource/i,
  /Telegram/i,
  /tonconnect.*(fetch|network|manifest)/i,
];

let failed = false;
const browser = await chromium.launch();
const ctx = await browser.newContext();

for (const route of ROUTES) {
  const page = await ctx.newPage();
  const errors = [];
  let manifestRequested = false;
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('request', (r) => { if (r.url().includes('tonconnect-manifest')) manifestRequested = true; });

  try {
    await page.goto(BASE + route, { waitUntil: 'load', timeout: 45000 });
    await page.waitForTimeout(4000); // даём клиентскому React смонтироваться
  } catch (e) {
    console.log(`❌ ${route}: не загрузился — ${e.message}`);
    failed = true;
    await page.close();
    continue;
  }

  const real = errors.filter((t) => !IGNORE.some((rx) => rx.test(t)));
  const bodyLen = (await page.textContent('body').catch(() => '') || '').trim().length;
  let extra = '';
  let extraOk = true;

  if (route === '/admin/login') {
    // Форм-инпутов на странице нет by design (вход — Telegram Login Widget);
    // проверяем, что antd-компоненты (Card и пр.) реально отрисовались клиентским React —
    // главный индикатор совместимости antd с текущей версией React.
    const antdNodes = await page.locator('[class*="ant-"]').count();
    extra = `antdNodes=${antdNodes}`;
    extraOk = antdNodes > 0;
  }
  if (route === '/miniapp') {
    const tcNodes = await page.locator('[id*="tc-" i], [class*="tonconnect" i], [data-tc-dropdown-container]').count();
    extra = `tonconnect: manifestReq=${manifestRequested} nodes=${tcNodes}`;
    extraOk = manifestRequested || tcNodes > 0;
  }

  const ok = real.length === 0 && bodyLen > 0 && extraOk;
  console.log(`${ok ? '✅' : '❌'} ${route}: consoleErrors=${real.length} body=${bodyLen}b ${extra}`);
  for (const t of real.slice(0, 5)) console.log(`   ↳ ${t.slice(0, 200)}`);
  if (!ok) failed = true;
  await page.close();
}

await browser.close();
console.log(failed ? 'BROWSER-SMOKE: FAIL' : 'BROWSER-SMOKE: PASS');
process.exit(failed ? 1 : 0);
