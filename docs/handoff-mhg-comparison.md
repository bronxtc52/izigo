# Разведка MHG (mhg-dev/mhg + mhg-dev/mhg-frontend) → сравнение с IziGo

> Read-only разведка двух приватных репозиториев (доступ ADMIN, активно пушатся). Цель —
> понять, что из MHG можно взять в наш `binar-mlm` (IziGo). Без технического стека — продуктовая
> логика. Ограничения IziGo неизменны: **движок бонусов не трогаем; валюта только USD; платёж и
> авторизация только Telegram** (см. memory `izigo-core-constraints`).

## Что это за репозитории
- **`mhg-dev/mhg`** — большой C#/.NET бэкенд (~14.4M C#). Это **зрелая прод-версия той же системы
  «Лидер»** (`Sdm.Leader.*`), что и legacy `leader-new`, но кратно крупнее: добавлены модули ESF
  (фискализация РФ), IdVerification, CDEK/FivePost/SPDEX (курьеры), Auth.Core, Jobs, Promo,
  Telegram, Platform, Mailers, e-sign.
- **`mhg-dev/mhg-frontend`** — Angular-монорепо (Nx) из 3 приложений: `mhg` (кабинет участника),
  `mhg-admin` (админка), `mhg-ref-page` (реф-лендинг) + `libs/shared`; обёрнуто в Capacitor
  (нативные iOS/Android).

---

## 1. Кабинет участника (apps/mhg/pages)

| Фича MHG | У нас сейчас | Вердикт |
|---|---|---|
| home (дашборд) | Income + KPI профиля (B4) | ✅ есть |
| finance (баланс/выписки) | баланс + выписка за период (A2) | ✅ есть |
| command (структура) | Team + генеалогия (B1) | ✅ есть |
| products / orders | Shop / заказы / автозаказ | ✅ есть |
| referals | реф-ссылка / код / share | ✅ есть |
| agreement / civil-contract | соглашение + акцепт (B3) | ✅ есть (юр.договор у них глубже) |
| edit-profile: account / payments / **heir** / **co-partner** / register-info | профиль базовый | 🟢 взять: совладелец (co-partner), наследник (heir), вкладка реквизитов |
| **notifications** | — (есть Telegram) | 🟢 взять: инбокс уведомлений в кабинете |
| **news / post / whats-new** | — | 🟢 взять: лента новостей + «что нового» |
| **knowledges (база знаний)** | — | 🟢 взять: обучение/материалы |
| **promos** | — | 🟢 взять: акции/промо участнику |
| **ratings (лидерборды)** | — | 🟢 взять: рейтинги/мотивация |
| **support / tickets** | — | 🟢 взять: тех-поддержка/тикеты |
| **survey (опросы)** | — | 🟢 взять: опросы / NPS |
| files (документы) | — | 🟡 опц.: хранилище документов участника |
| events / seat-map / seat-tickets | — | 🟡 ниша: билеты на события с рассадкой |
| app-version (force-update) | — | 🟢 взять: гейт версии приложения / soft-update |
| auth (пароль / recovery / SSO / egov-sign) | Telegram-only | 🔴 by design |

## 2. Админка (apps/mhg-admin + API/Admin/*)

| Фича MHG | У нас сейчас | Вердикт |
|---|---|---|
| users: Search / Edit / Finance / **Statements** / Export / **PII** / Gifts / DeletionLock | Users + MemberCard (роль/кошелёк) | 🟢 взять: поиск, экспорт, выписки, PII-режим, подарки, lock на удаление |
| hierarchy / teams | генеалогия (B1) + ручной плейсмент (B2) | ✅ есть |
| finances: BV / PV / **period-bonuses** / MP / FinStats | отчёты (A1) + Finances | 🟢 взять: BV/PV-аналитика, периоды начислений (read-view) |
| orders: warehouse / waybills / receipts / MoySklad / packing | Orders / Operations | 🟡 склад/логистика — только если пойдём в физический товар |
| **notifications + mailings** | — | 🟢 взять: рассылки участникам (доставка через Telegram-бот) |
| **news / shop-news / stories** | — | 🟢 взять: контент-управление + сторис |
| **kb / kb-v2** | — | 🟢 взять: управление базой знаний |
| **promos / promo-news / user-gifts** | — | 🟢 взять: кампании + подарки участникам |
| **ratings** | — | 🟢 взять: управление рейтингами/лидербордами |
| **tickets (support)** | — | 🟢 взять: тикет-система поддержки |
| **feature-flags (AdminFeatureController)** | — | 🟢 взять: рантайм фиче-флаги |
| **lead / CRM (AdminLeadController + LeadController)** | — | 🟢 взять: воронка лидов / пресейл до регистрации |
| **translation (i18n из админки)** | locales статикой | 🟢 взять: правка переводов без релиза |
| settings / payment-settings | маркетинг-план + соглашение | 🟢 взять: общий settings-экран приложения |
| sys: monitor / logs / outbox / jobs / queue-calc-team-volume | Sentry + server-watchdog | 🟡 частично: outbox-паттерн, монитор джоб/очередей расчёта |
| id-verification / contractor / ie (ИП) / legal-data | KYC | 🟡 KYC-глубже (юрисдикция РФ/КЗ) |
| agreements (по странам/категориям) | соглашение (B3) | 🟢 взять: версии соглашения по странам/категориям |
| seat-maps / seatmap-editor / usher | — | 🟡 ниша: события/рассадка |
| cdek / fivepost / spdex (курьеры) | — | 🔴 регион (РФ/СНГ) |
| esf / egov-sign / robokassa / kaspi / moysklad | — | 🔴 регион / фискализация |

## 3. Платформенное — то, что НЕ берём (ограничения / регион)

| Область | MHG | IziGo | Вердикт |
|---|---|---|---|
| Платёжные шлюзы | ~15: Stripe / Kaspi / Robokassa / CloudPayments / FreedomPay / Payme / Qpay / Wooppay / PulPal / Payze / Pikassa / TipTopPay / AnyMoney / UBG / Advcash | только TON Pay | 🔴 by design |
| Авторизация | пароль / SSO / egov e-sign / recovery / ForgotPassword | только Telegram (initData / Login Widget) | 🔴 by design |
| Валюта | мультивалюта + курсы (CurrencyRates) | только USD | 🔴 by design |
| Фискализация / курьерка | ESF / CDEK / FivePost / SPDEX / MoySklad | — | 🔴 регион |
| Стек | C#/.NET + Angular/Capacitor (iOS/Android) | Laravel + Next.js / Telegram Mini App | ℹ️ берём логику, не код |

**Легенда:** ✅ уже есть · 🟢 кандидат на адаптацию · 🟡 опц. / нишевое / инфра · 🔴 исключено (ограничения или регион).

---

## Вывод
MHG — это «Лидер» спустя годы продакшена. ~95% объёма — **региональные платежи, фискализация,
курьерка, физический склад и не-Telegram авторизация**, которые мы по ограничениям/географии не
берём. Но есть **переносимое золото для роста и удержания**, которого у нас нет:

1. **Вовлечение / мотивация:** ratings (лидерборды), stories, promos + подарки, survey / NPS.
2. **Контент:** news / whats-new, knowledge base (обучение).
3. **Поддержка:** тикет-система.
4. **Рост:** lead / CRM-воронка (пресейл до регистрации).
5. **Коммуникации:** центр уведомлений + рассылки (через наш Telegram-бот).
6. **Ops / инфра:** feature-flags, outbox, job-монитор, i18n из админки, user-выписки / экспорт /
   PII-режим, co-partner (совладелец) и наследник в профиле.

### Возможный следующий блок «C» (под наши ограничения) — на выбор приоритета
- **«Движок удержания»:** лидерборды + промо/подарки + тикеты поддержки.
- **«Рост»:** lead/CRM-воронка + реф-лендинг.
- **«Контент/коммуникации»:** новости + центр уведомлений/рассылки (Telegram) + база знаний.
- **«Ops»:** feature-flags + i18n из админки + user-экспорт/PII.

_Это разведка — код не трогался. Источник: read-only обход структуры обоих репозиториев._
