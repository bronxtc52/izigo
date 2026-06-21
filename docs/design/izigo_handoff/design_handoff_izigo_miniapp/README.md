# Handoff: IziGo — Telegram Mini App (партнёрский кабинет + админ)

## Overview
Редизайн Telegram Mini App для MLM-платформы IziGo. Узкий мобильный экран внутри Telegram: верхний хедер Telegram, контент со скроллом, нижний таб-бар на 4 вкладки (+5-я «Админ» для привилегированных ролей). Цель редизайна — **гарантированный контраст (WCAG AA)**, чистая иерархия, «дышащие» отступы и полноценная поддержка светлой/тёмной темы Telegram.

Главная боль старого UI, которую этот редизайн исправляет: цветные элементы перебивали фон, текст сливался, темы не учитывались. Ключевое решение — **статусные/бонусные/ролевые бейджи = насыщенный текст на мягком тинте**, а не заливка цветом по цветному фону.

## About the Design Files
Файл в этом бандле (`IziGo Redesign.dc.html`) — это **дизайн-референс, собранный в HTML**. Это прототип, показывающий внешний вид и поведение, а **не продакшн-код для копирования**. Задача — **воссоздать эти экраны в реальном кодовом окружении проекта**: **Next.js + Ant Design (antd)**, темизация через `ConfigProvider`, токены биндятся к Telegram `themeParams`. Используйте существующие компоненты antd и паттерны проекта; не тащите HTML/инлайн-стили напрямую.

Открыть референс: это `.dc.html` — откройте в браузере проекта-превью. Для статичного просмотра вёрстки достаточно прочитать разметку (инлайн-стили читаются как значения токенов).

## Fidelity
**High-fidelity (hifi).** Финальные цвета, типографика, отступы и состояния. Воссоздавать UI пиксель-в-пиксель средствами antd. Все hex-значения, размеры и радиусы ниже — целевые.

---

## Tech constraints (обязательно)
- **Стек:** Next.js + Ant Design (antd).
- **Темизация:** один `ConfigProvider` с `theme={{ algorithm, token }}`. Алгоритм выбирается по `Telegram.WebApp.colorScheme`:
  ```tsx
  import { theme, ConfigProvider } from 'antd';
  const isDark = WebApp.colorScheme === 'dark';
  <ConfigProvider theme={{
    algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
    token: isDark ? darkTokens : lightTokens,
  }}>
  ```
- **Компоненты — только из antd:** `Card`, `Statistic`, `Tag`, `List`, `Progress`, `Button`, `Segmented`, `Input`, `Radio`, `Avatar`, `Divider`, нижний таб-бар (кастомный на `Flex` + иконки, antd не даёт готового bottom-tab). Не предлагать компоненты, которых нет в antd.
- **Шрифты:** системный стек Telegram для UI; крупные цифры/суммы и заголовки — **Manrope** (`500–800`, `font-feature-settings:'tnum'` для табличных цифр). Если Manrope недоступен — системный fallback.

---

## Design Tokens — antd `theme.token`

### Light (`defaultAlgorithm`)
| Token | Value | Bind → Telegram themeParams |
|---|---|---|
| `colorPrimary` | `#2563EB` | `button_color` (см. примечание) |
| `colorBgBase` | `#EEF1F5` | `secondary_bg_color` |
| `colorBgContainer` | `#FFFFFF` | `bg_color` |
| `colorText` | `#14213A` | `text_color` |
| `colorTextSecondary` | `#6B7785` | `hint_color` |
| `colorBorder` | `#E7ECF2` | `section_separator_color` |
| `colorSuccess` | `#0E9E6E` | — |
| `colorWarning` | `#C77700` | — |
| `colorError` | `#D33A36` | `destructive_text_color` |
| `borderRadius` | `14` | — |

### Dark (`darkAlgorithm`)
| Token | Value | Bind → Telegram themeParams |
|---|---|---|
| `colorPrimary` | `#5AA2F0` | `button_color` |
| `colorBgBase` | `#0E1621` | `secondary_bg_color` |
| `colorBgContainer` | `#18222E` | `bg_color` |
| `colorText` | `#EAF0F6` | `text_color` |
| `colorTextSecondary` | `#93A4B6` | `hint_color` |
| `colorBorder` | `#243140` | `section_separator_color` |
| `colorSuccess` | `#3FBE84` | — |
| `colorWarning` | `#E0B050` | — |
| `colorError` | `#F0635E` | `destructive_text_color` |
| `borderRadius` | `14` | — |

**Примечание по contrast/binding:**
- Текст по фону везде проходит WCAG AA: основной ≥ 7:1, вторичный ≥ 4.5:1, бейджи ≥ 4.5:1.
- Если `themeParams.button_color` от Telegram даёт низкий контраст с белым текстом кнопки — оставлять **фиксированный** `colorPrimary` IziGo (значения из таблиц), не подменять.
- Кнопки primary: текст всегда `#FFFFFF` на `#2563EB` (в обеих темах фон кнопки `#2563EB`, контраст 4.9:1).

### Семантические tint-палитры (бейджи) — текст / фон
Не цвет-по-цвету. Формат: `текст` на `фоне`.
| Назначение | Light текст / фон | Dark текст / фон |
|---|---|---|
| Бинар / leader (синий) | `#1D4ED8` / `#E8F0FE` | `#7FB3F5` / `#1B2C44` |
| Реферал / finance / success (зелёный) | `#0E7C5A` / `#E2F4EF` | `#4FC79A` / `#16322A` |
| Лидер / owner (фиолетовый) | `#5B45C9` / `#EDEAFB` | `#A99BF0` / `#272148` |
| Ранг / support (янтарный) | `#9A6700` / `#FBEFD9` | `#E0B050` / `#352B19` |
| partner / нейтральный | `#54606F` / `#EEF1F5` | `#A9B6C4` / `#222D3A` |

### Цвета «точек» бонусов (dot перед типом)
| Тип | Light | Dark |
|---|---|---|
| Бинар | `#2563EB` | `#5AA2F0` |
| Реферал | `#0E9E6E` | `#3FBE84` |
| Лидер | `#6D5BD0` | `#A99BF0` |
| Ранг | `#C77700` | `#E0B050` |

### Spacing & shape
- Радиусы: контейнеры/карты `16`, под-карты `13–14`, бейджи/инпуты `6–11`, кнопки `10–11`, аватары `50%`.
- Внутренний паддинг экрана: `14px`. Гэп между блоками: `12px`. Паддинг карт: `13–18px`.
- Размеры устройства-мокапа: ширина экрана `316px`, хедер `46px`, таб-бар `62px`, контент — между ними со скроллом.
- Тень карт (опц., antd обычно даёт свою): `0 1px 3px rgba(0,0,0,.05)` light.

### Typography
- Заголовок хедера: Manrope 700, `15px`, `colorText`.
- Hero-сумма: Manrope 800, `33px`, `line-height:1.1`, табличные цифры.
- Имя в профиле: Manrope 800, `19px`.
- Числа в статах/карточках: Manrope 700–800, `16–22px`.
- Основной текст: `13–13.5px` weight 600 для имён, 400–600 для контента.
- Вторичный/подписи: `10.5–12px`, `colorTextSecondary`.
- Бейджи: `10–11px`, weight 600–700.

---

## Screens / Views

Нижний таб-бар (5 вкладок, иконки stroke 1.9, активная = `colorPrimary`, неактивная light `#98A2AE` / dark `#6F7E8E`):
`Доход` (wallet) · `Команда` (people) · `Ранг` (trophy) · `Профиль` (user) · `Админ` (shield, только для ролей owner/finance/leader/support).

### 1 · Доход
**Purpose:** обзор начислений и управление пакетом.
**Layout:** вертикальный стек карт, gap 12.
**Components:**
- **Hero-карта** (`Card`): лейбл «Всего начислено» (secondary) → сумма `$4 182.50` (Manrope 800, 33px) → success-чип `▲ $128.00 за неделю` (tint зелёный). `Divider`. Низ: слева «Доступно к выводу» + `$3 010.00`, справа `Button type="primary"` «Вывести».
- **Бонусы по типам** — заголовок «Бонусы по типам» (secondary, 12px, 700) + сетка 2×2 мини-карт. Каждая: цветная точка 8px + тип (secondary) + сумма (Manrope 700, 18px). Данные: Бинар `$1 920.00`, Реферал `$1 260.00`, Лидер `$640.00`, Ранг `$362.50`.
- **Пакет** (`Card`): заголовок «Пакет» + success-бейдж «Silver активен». Под ним `Segmented`-подобный ряд из 3 опций (можно `Segmented` block или 3 `Card`): Bronze 100 PV $100 · **Silver 300 PV $300 (active)** · Gold 1000 PV $1000. Активная: рамка `1.5px colorPrimary`, фон `#F1F6FE`/dark `#152434`, бейдж-галочка сверху по центру (кружок primary, ✓).
- **Последние начисления** (`List`): строки = `Tag` типа (tint) + время (secondary) слева, сумма `+$NN.NN` (success, Manrope 700) справа. Данные: Бинар +$48.00 (2 ч), Реферал +$30.00 (5 ч), Ранг +$12.50 (вчера).

### 2 · Команда
**Purpose:** даунлайн деревом с отступами по уровням.
**Components:**
- **Сводка** (`Card`, 3 `Statistic` через `Divider type="vertical"`): В команде `24`, Активных `18` (success), Личных `6`.
- **Фильтр** `Segmented`: Все (active) · Активные · Новые.
- **Дерево** (`Card`): корень «Вы» (аватар primary, ник, бейдж «Manager»). Вложенность через контейнер `margin-left:14–15px; border-left:1.5px solid colorBorder; padding-left:13–14px`. Узел = `Avatar` с инициалами (tint по ветке) + имя + статус-`Tag` (`активен`=зелёный tint / `нов`=синий tint). Иерархия: Анна К.(активен)→[Игорь П. активен, Мария С. нов]; Дмитрий Л.(активен)→[Олег В. активен]; Светлана Р.(нов).

### 3 · Ранг
**Purpose:** текущий → следующий ранг и прогресс к нему.
**Components:**
- **Hero ранга** (`Card`): иконка-трофей в tint-квадрате 52px (фиолетовый), «Текущий ранг» + `Manager` (Manrope 800, 23px), справа «далее» + `Director ↗`. Под ним степпер прогрессии — 4 сегмента-полоски (2 заполнено primary, 1 частично, 1 пусто) + подписи Partner/Manager(active)/Director/Leader.
- **Малая ветка PV** (`Card`): заголовок + `6 400 / 10 000` → `Progress` 64% (синий градиент, height 10, radius 6, track `colorBgBase`/dark `#121C27`). Подпись «Осталось 3 600 PV до Director».
- **Приглашённые** (`Card`): `8 / 12` → `Progress` 67% (зелёный градиент). «Осталось 4 активных партнёра».
- **Награда** (info-карта на primary-tint): «Ранг Director даёт» + 2 пункта «＋ Лидерский бонус 3% от ветки», «＋ Разовая награда $500».

### 4 · Профиль
**Purpose:** данные партнёра + реф-ссылка.
**Components:**
- **Шапка** (`Card`, центрированная): `Avatar` 66px (градиент primary, «АМ») → имя «Алексей Морозов» (Manrope 800, 19px) → 2 `Tag`: «● Активен» (зелёный tint), «Manager» (фиолетовый tint). `Divider`. Ряд из 3 `Statistic`: Приглашено `8`, В команде `24`, ID `#10482`.
- **Реферальный код** (`Card`): лейбл → код `IZIGO-AM4821` (Manrope 800, 20px) → строка-инпут с ссылкой `t.me/izigo_bot?start=AM4821` (read-only `Input`) → ряд: `Button type="primary"` «Копировать ссылку» (flex:1) + квадратная иконка-кнопка «↗» (share). Копирование = `navigator.clipboard.writeText` + `message.success`.
- **Настройки** (`List`): Уведомления (Вкл ›), Язык (Русский ›), Выйти (`colorError`).

### 5 · Админ (роли: owner/finance/leader/support)
**Purpose:** управление участниками и маркетинг-планом.
**Components:**
- **Переключатель** `Segmented` block: «Участники» · «Маркетинг-план».
- **Участники:** `Input` с иконкой поиска «Поиск по имени или ID» → `List` участников: `Avatar` (инициалы, tint по роли) + имя + подпись `#ID · Ранг` + ролевой `Tag` справа (owner=фиолетовый, finance=зелёный, leader=синий, support=янтарный, partner=нейтральный). 6 строк-примеров.
- **Карточка участника** (drill-in, хедер с `‹`): шапка с аватаром/именем/`#ID · дата`/статусом; `List` данных (Ранг: Director, Пакет: Gold · 1000 PV, Команда: 142 чел., Оборот ветки: $48 200); блок «Назначить роль» — сетка 2×2 из выбираемых чипов (выбранный = рамка+tint+✓: finance), `Button type="primary"` «Сохранить роль».
- **Маркетинг-план:** блок «Режим размещения» — `Radio.Group` (вертикальный, кастомные карточки): **Бинар** (active, «Две ветки, авто-баланс слабой»), Матрица 3×N, Линейный. Блок «Бонусы рангов» — `List` строк с редактируемым значением (можно `InputNumber`/`Input` в pill-стиле): Manager 3%, Director 5%, Leader 7%, Глубина бинара 15 ур. `Button type="primary"` «Сохранить план».

---

## Interactions & Behavior
- **Навигация:** нижний таб-бар переключает 4 (+1) корневых вкладки. Вкладка «Админ» рендерится только при роли ∈ {owner, finance, leader, support}.
- **Тема:** реактивно по `Telegram.WebApp.colorScheme` + `WebApp.onEvent('themeChanged', …)`; перестроить `ConfigProvider` токены.
- **Копирование реф-ссылки:** `navigator.clipboard` → `message.success('Ссылка скопирована')`; можно `WebApp.HapticFeedback`.
- **Вывод средств / Сохранить роль / Сохранить план:** заглушки-обработчики (mutation в реальном API), показывать loading на `Button`.
- **Поиск участников:** фильтрация `List` по имени/ID (debounce).
- **Состояния:** loading (`Skeleton`/`List loading`), empty (`Empty`), error (`message.error`). Активные/disabled состояния — стандартные antd.
- **Прогресс-бары:** `Progress` со `strokeColor` (можно градиент-объект), `showInfo={false}`, `trailColor` = track из токенов.

## State Management
- `colorScheme` (light/dark) — из Telegram, в контексте темы.
- `activeTab` — текущая вкладка.
- `userRole` — для гейтинга вкладки «Админ».
- `adminTab` — 'members' | 'plan'.
- `selectedMember` — drill-in карточка.
- `searchQuery` — фильтр участников.
- Данные (доход, бонусы, команда, ранг-прогресс, участники, план) — из API; в мокапе захардкожены.

## Assets
- **Иконки:** инлайновые SVG (stroke, 24×24, width 1.9) для таб-бара и UI. В проекте заменить на `@ant-design/icons` или существующий icon-set: wallet→`WalletOutlined`, people→`TeamOutlined`, trophy→`TrophyOutlined`, user→`UserOutlined`, shield→`SafetyOutlined`, поиск→`SearchOutlined`, share→`ExportOutlined`. Точные формы — в HTML.
- **Шрифт:** Manrope (Google Fonts) для цифр/заголовков.
- Изображений/логотипов-ассетов нет; логотип IziGo в шапке интро — простой текстовый знак.

## Files
- `IziGo Redesign.dc.html` — все 5 экранов в light и dark + таблица токенов antd. Это единственный референс; все значения берите отсюда и из этого README.
