# ТЗ + план: редизайн Mini App «Aurora»

**Дата:** 2026-06-23
**Тип:** редизайн UI Telegram Mini App (партнёрский кабинет). Frontend-only.
**Макеты:** Claude Design «IziGo Mini App — Redesign 2026» (файлы `Aurora Core`, `Aurora Screens`).
**Handoff/спека:** `docs/design/handoff-2026-06-23-miniapp-redesign/`.

## Цель
Применить новый визуальный язык **Aurora** (crypto-premium) к Mini App: тёмный родной режим
с aurora-фоном и градиентными акцентами TON (фиолетовый→циан), светящийся баланс, glassy-карты;
светлый режим — «холодный aurora» с тем же акцентом. Обе темы по Telegram `colorScheme`,
контраст AA, i18n-готовность.

## Жёсткие границы (НЕ трогаем)
- Движок расчёта бонусов (`Modules/Calculator`), auth (initData), платёжную логику (TON Pay,
  withdrawals) — только UI.
- Калькулятор и веб-админку (палитра Mini App изолирована в `views/miniapp/`).

## Ключевое решение по шрифту (i18n)
**Space Grotesk не содержит кириллицы.** Поэтому:
- `balanceFont` (Space Grotesk) — только чисто-числовые узлы ($суммы, PV, счётчики).
- Кирилличные заголовки/имена/текст — Manrope (как сейчас, `numFont`) или системный стек.
Иначе ru/kk/… текст ломается на фолбэк.

## Палитра Aurora (источник истины — `telegram.js::miniAppPalette`)
| | Dark | Light |
|---|---|---|
| bg (radial scrbg) | `#090C16` | `#EFF1F9` |
| surface (glassy/solid) | `rgba(255,255,255,.045)` | `#FFFFFF` |
| sheet/elevated (opaque) | `#141A2B` | `#FFFFFF` |
| fg | `#E9ECF8` | `#161A40` |
| muted | `#9AA3C7` | `#6B7194` |
| accent (links/active) | `#8EE6FF` | `#6D28D9` |
| border | `rgba(255,255,255,.09)` | `#E7E4F6` |
| success / warning / error | `#6FE0AE` / `#E6C06A` / `#F0635E` | `#0E7C5A` / `#9A6700` / `#D33A36` |
| balGrad | `linear-gradient(92deg,#C9B8FF,#6FE9FF)` | `linear-gradient(92deg,#6D28D9,#0E7490)` |
| primBg / primTxt | `linear-gradient(92deg,#A78BFA,#5EE3F5)` / `#0a0a16` | `linear-gradient(92deg,#7C3AED,#2563EB)` / `#fff` |

Градиент — только на hero / балансе / CTA / прогрессе. Остальное спокойное.

## План по PR (ветка `feat/miniapp-aurora-redesign`)

### PR1 · Foundation  ← текущий
- [ ] `layout.js`: подключить Space Grotesk (`--font-space-grotesk`).
- [ ] `tokens.js`: добавить `balanceFont` (Space Grotesk, tnum); `numFont` (Manrope) оставить.
- [ ] `telegram.js`: `miniAppPalette` → значения Aurora + градиент-токены
      (scrbg, heroBg, heroBorder, heroGlow, balGrad, primBg, primGlow, ghostBg, sheetBg, scrim, pos).
- [ ] `telegram.js`: `antdThemeFromTelegram` — `colorBgElevated`=sheet (opaque), радиусы, accent.
- [ ] `MiniAppShell.js`: радиальный фон экрана (`pal.scrbg`).

### PR2 · Экраны
- hero с градиентным балансом (`balGrad` + balanceFont) и свечением; glassy-тайлы бонусов;
  градиентный `Progress`; аватар в градиентном кольце; таб-бар Aurora; тинты под dark (translucent).

### PR3 · Доводка
- TON Pay/модалки/состояния; `NotificationInbox`, `Helpdesk`; success-check на градиенте.

### Гейт 4
- `npm run build` зелёный; ручной клик-тест обеих тем; контраст AA (баланс, бейджи, CTA).

## Сценарии (привязка кода)
- Партнёр открывает кабинет в тёмной/светлой теме Telegram → Aurora корректна в обеих.
- Баланс/суммы читаемы (балансный градиент проходит AA), кирилличный текст не ломается.
- Необратимые действия (вывод/оплата/memo) визуально выделены (PR3).
