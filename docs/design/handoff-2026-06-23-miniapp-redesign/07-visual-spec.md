# 07 · Visual Spec для разработки

Дизайн-токены, типографика, отступы и компоненты редизайна Mini App. Мост от макетов к коду:
**Next.js + Ant Design** (тема через единый `ConfigProvider`, токены биндятся к Telegram
`themeParams`). Зеркало визуальной спеки из Claude Design.

**Макеты (Claude Design):** проект «IziGo Mini App — Redesign 2026» —
`https://claude.ai/design/p/889b89a1-1645-45bd-b840-a6a7c2ef39c0`
Файлы: `Core` · `Commerce & States` · `Support & Statement` · `Modals` · `Visual Spec`.

Связано с: [[02-constraints]] · [[05-design-system-reference]] · [[06-creative-brief]].

---

## 01 · Цветовые токены

### Light
| Роль | HEX | antd token | Telegram themeParams |
|---|---|---|---|
| Accent / Primary | `#2563EB` | `colorPrimary` | `button_color` * |
| Фон экрана | `#EEF1F5` | `colorBgLayout` | `secondary_bg_color` |
| Поверхность / карта | `#FFFFFF` | `colorBgContainer` | `bg_color` |
| Поверхность-акцент | `#F1F6FE` | — | — |
| Текст | `#14213A` | `colorText` | `text_color` |
| Вторичный текст | `#6B7785` | `colorTextSecondary` | `hint_color` |
| Граница | `#E7ECF2` | `colorBorder` | `section_separator_color` |
| Success | `#0E9E6E` | `colorSuccess` | — |
| Warning | `#C77700` | `colorWarning` | — |
| Error | `#D33A36` | `colorError` | `destructive_text_color` |
| Неактивная вкладка | `#98A2AE` | — | — |

### Dark
| Роль | HEX | antd token | Telegram themeParams |
|---|---|---|---|
| Accent / Primary | `#5AA2F0` | `colorPrimary` | `button_color` * |
| Фон экрана | `#0E1621` | `colorBgLayout` | `secondary_bg_color` |
| Поверхность / карта | `#18222E` | `colorBgContainer` | `bg_color` |
| Поверхность-акцент | `#152434` | — | — |
| Текст | `#EAF0F6` | `colorText` | `text_color` |
| Вторичный текст | `#93A4B6` | `colorTextSecondary` | `hint_color` |
| Граница | `#243140` | `colorBorder` | `section_separator_color` |
| Success | `#3FBE84` | `colorSuccess` | — |
| Warning | `#E0B050` | `colorWarning` | — |
| Error | `#F0635E` | `colorError` | `destructive_text_color` |
| Неактивная вкладка | `#6F7E8E` | — | — |

\* **Primary фиксирован:** кнопка primary всегда `#2563EB` + белый текст в обеих темах
(контраст 4.9:1). Если `themeParams.button_color` от Telegram даёт низкий контраст — **не
подменять**, оставлять фирменный.

---

## 02 · Семантические тинты (бейджи)

Принцип: **насыщенный текст на мягком фоне**, не цвет-по-цвету. Один набор покрывает типы
бонусов, статусы и роли. Формат: `текст` / `фон`.

| Тинт | Light текст / фон | Dark текст / фон | Применение |
|---|---|---|---|
| Синий | `#1D4ED8` / `#E8F0FE` | `#7FB3F5` / `#1B2C44` | бонус **binary**, статус «нов», роль leader |
| Зелёный | `#0E7C5A` / `#E2F4EF` | `#4FC79A` / `#16322A` | бонус **referral**, статус «активен», success, finance |
| Фиолетовый | `#5B45C9` / `#EDEAFB` | `#A99BF0` / `#272148` | бонус **leader**, роль owner |
| Янтарный | `#9A6700` / `#FBEFD9` | `#E0B050` / `#352B19` | бонус **rank**, support, статус «в работе» |
| Нейтральный | `#54606F` / `#EEF1F5` | `#A9B6C4` / `#222D3A` | partner, нейтральные метки |

### Точки 4 бонусов (насыщенные, по теме)
| Тип | Light | Dark |
|---|---|---|
| binary / Бинар | `#2563EB` | `#5AA2F0` |
| referral / Реферал | `#0E9E6E` | `#3FBE84` |
| leader / Лидер | `#6D5BD0` | `#A99BF0` |
| rank / Ранг | `#C77700` | `#E0B050` |

Соответствие: статус active→зелёный, нов→синий; роли owner→фиол., finance→зел., leader→син.,
support→янт., partner→нейтр.

---

## 03 · Типографика

- **UI-текст** — системный стек Telegram (`-apple-system, "Segoe UI", Roboto, system-ui`).
- **Цифры и заголовки** — **Manrope** (500–800) с `font-feature-settings:'tnum'` (табличные
  цифры для выравнивания сумм по разрядам).

| Назначение | Стиль |
|---|---|
| Hero-сумма | Manrope 800 · 36/40 · tnum |
| Имя в профиле / заголовок | Manrope 800 · 19 |
| Числа в статах/картах | Manrope 700 · 16–18 · tnum |
| Имя/основной текст | System 600 · 13.5 |
| Лейбл / подпись | System 600 · 12 · secondary |
| Бейдж | System 700 · 11 |

Формат денег: `$1 234.50`, только USD/USDT.

---

## 04 · Отступы и радиусы

- **Spacing:** padding экрана `14`, гэп блоков `12`, padding карты `16` (13–18), гэп в строке `8–10`.
- **Radius:** карта `16–18`, под-карта `13–14`, кнопка/инпут `11`, бейдж `999`, аватар `50%`.
- Тень карт (light): `0 1px 2px rgba(16,24,40,.04)`; в dark — без тени, разделение границей.

---

## 05 · Компоненты

Реализация — antd-эквиваленты, кастом только где antd не покрывает.

| Компонент | antd | Заметки |
|---|---|---|
| Кнопка primary | `Button type="primary"` | фикс `#2563EB`, белый текст |
| Кнопка ghost | `Button` | фон surface2, текст accent |
| Кнопка опасная | `Button danger` | вывод/выход, рамка/текст error |
| Бейдж/тег | `Tag` (bordered=false) | тинт текст+фон (см. §02) |
| Карта | `Card` | radius 16–18 |
| Список | `List` | разделители border |
| Прогресс | `Progress` | `strokeColor` градиент, `trailColor`=surface2 |
| Сегмент | `Segmented` | radius 10 |
| Инпут / textarea | `Input` / `Input.TextArea` | фон surface2 |
| Аватар | `Avatar` | инициалы, градиент или тинт по ветке |
| Узел дерева | кастом | отступ + `border-left` по уровням |
| Нижний таб-бар | кастом на `Flex` | 5–7 вкладок, hit-target ≥44px |
| Модалка / боттомшит | `Modal` / Drawer-стиль | grabber, radius 24 сверху |
| Пустое состояние | `Empty` / `Result` | иконка + текст + CTA |

---

## 06 · antd ConfigProvider — токены

Единый `ConfigProvider`; алгоритм по `WebApp.colorScheme`, перестройка на `themeChanged`.

```jsx
const isDark = WebApp.colorScheme === 'dark';

const lightToken = {
  colorPrimary: '#2563EB', colorBgLayout: '#EEF1F5', colorBgContainer: '#FFFFFF',
  colorText: '#14213A', colorTextSecondary: '#6B7785', colorBorder: '#E7ECF2',
  colorSuccess: '#0E9E6E', colorWarning: '#C77700', colorError: '#D33A36',
  borderRadius: 16, fontFamily: 'Manrope, -apple-system, system-ui, sans-serif',
};
const darkToken = {
  colorPrimary: '#5AA2F0', colorBgLayout: '#0E1621', colorBgContainer: '#18222E',
  colorText: '#EAF0F6', colorTextSecondary: '#93A4B6', colorBorder: '#243140',
  colorSuccess: '#3FBE84', colorWarning: '#E0B050', colorError: '#F0635E',
  borderRadius: 16, fontFamily: 'Manrope, -apple-system, system-ui, sans-serif',
};

<ConfigProvider theme={{
  algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
  token: isDark ? darkToken : lightToken,
}}>
  {/* … */}
</ConfigProvider>
```

> Тинты бейджей (§02) и точки бонусов — отдельные значения вне antd-токенов; держать в
> `tokens.js` как мапы по теме (как в текущем коде: `tint()`, `bonusTint()`, `bonusDot()`).

---

## 07 · Правила реализации

- **Контраст WCAG AA** в обеих темах: основной текст ≥ 7:1, вторичный и бейджи ≥ 4.5:1.
- **Деньги — табличные цифры:** Manrope + `'tnum'`, формат `$1 234.50`, только USD/USDT.
- **Бейджи = текст на тинте**, не сплошная заливка; 5 тинтов покрывают бонусы/статусы/роли.
- **Primary-кнопка фиксирована** (`#2563EB` + белый), не подменять Telegram-цветом при низком
  контрасте.
- **Необратимые действия** (вывод, оплата, TON-адрес, memo) — явно выделены + предупреждение
  «ошибка необратима».
- **Таб-бар 5–7 вкладок** (2 по feature-флагам); компактный режим при 6–7 иконках на ≈0.32 экрана.
- **i18n-готовность** (kk/ru/mn/uz/ky/az): не фиксировать ширину под одну строку; перенос/усечение.
- **Safe-area + хедер Telegram:** контент под системным хедером, нижняя safe-area под таб-баром.
