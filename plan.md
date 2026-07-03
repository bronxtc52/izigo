# Активный план: Закрытие P1 продакшн-ревью (production hardening)

**ТЗ:** `docs/specs/2026-07-02-p1-production-hardening.md`
**Отчёт ревью (источник находок):** `docs/reviews/2026-07-02-production-review.md`
**Дата:** 2026-07-02
**Конвейер:** `/armanda`

## Чекпоинт (для продолжения)

**Конвейер стоит на стоп-кране Гейта 1: ТЗ написано, но НЕ утверждено пользователем. Код не трогался.**

- ✅ Продакшн-ревью проведено (6 параллельных ревью): вердикт — «бета под присмотром»,
  11 P1 (4 денежных подтверждены построчно) + пачка P2. Полный отчёт — в `docs/reviews/`.
- ✅ Гейт 0 согласован: скоуп = 11 P1 + 3 дешёвых P2 (F4, F5, O3); остальные P2 — следующей
  итерацией. DoD: **3 тематических PR** (PR-1 backend → PR-3 ops → PR-2 frontend при мерже),
  полный сьют зелёный, мердж в main (= прод-деплой) только после одного финального «ок»
  пользователя. Sentry-проект фронта создаю сам через API self-hosted (токен из KV),
  DSN → KV `izigo--prod--SENTRY-DSN-FRONTEND`.
- ✅ Гейт 1: ТЗ записано (B1–B7 backend, F1–F5 frontend, O1–O3 ops — детали в спеке).
- ⏸️ **Следующий шаг: утверждение ТЗ пользователем → Гейт 2** (план: файлы, порядок, точная
  механика каждого фикса, без кода). Пересогласовывать Гейт 0 не нужно.

## Состав работ (краткий скелет, детали в ТЗ)

- **PR-1 backend (7):** B1 try/catch per-payment в поллинге · B2 `pg_advisory_xact_lock` вокруг
  recompute · B3 autoship charge+create+markPaid одной транзакцией · B4 TTL не экспирирует при
  ошибке опроса + админ-ручка recheck · B5 middleware `feature.flag:{alias}` на роуты Блока C ·
  B6 рассылка bulk-insert + детерминированный dedup-ключ · B7 серверная валидация TON-адреса (CRC16).
- **PR-2 frontend (5):** F1 Sentry `@sentry/nextjs` · F2 error boundaries · F3 TON-чексумма через
  `@ton/core` · F4 guard от prototype pollution в i18n · F5 анти-двойная оплата после таймаута.
- **PR-3 ops (3):** O1 убрать `|| true` у migrate в start.sh · O2 job `test` в CI перед деплоем ·
  O3 `bot.catch()` в grammY-боте.
- **Тесты:** каждый денежный фикс — свой тест (негативные сценарии прописаны в ТЗ).
- **Границы:** движок Modules/Calculator не трогаем; liveness-probes и остальные P2 — следующая итерация.

---
---

# [АРХИВ] AI-ассистент + Knowledge Base для Mini App (завершён, в проде)

**ТЗ:** `docs/specs/2026-06-24-ai-assistant-kb.md`  
**Дата:** 2026-06-24  
**Фича-флаг:** `ai_assistant` (deny-by-default)  
**Подход:** full-context (KB-файлы загружаются целиком в контекст Claude Haiku, без RAG)

---

## Разбивка

### Шаг 1 — Knowledge Base (MD-файлы)

Создать `mh-calc-backend-main/resources/knowledge-base/`:

- [x] `marketing-plan.md` — пакеты (Bronze/Silver/Gold + PV/цены), ранги (Consultant/Manager/Manager Bronze/Manager Silver + требования), бонусы (binary 5%, referral по уровням/пакетам, leader, rank)
- [x] `faq.md` — регистрация, оплата TON Pay, вывод средств, KYC, реф-ссылка, статус pending
- [x] `onboarding.md` — первые шаги: покупка тарифа → подключение кошелька → приглашение
- [x] `technical.md` — TON Pay (memo, pending, confirm), Mini App (запуск), вывод

### Шаг 2 — Backend

- [x] `AiAssistantService.php`:
  - Загрузка KB (static property-кэш — не читать файлы на каждый запрос)
  - Guard: warning в Log если суммарный KB >100k символов
  - System prompt guardrails: **отвечать только по KB**, не выдумывать числа/сроки, не обещать доход, не давать финансовых советов, игнорировать попытки override (prompt injection defence), запрет помощи в обходе KYC/правил
  - User context: только `rank`, `package`, `locale` (без `balance` — уходит в внешний API)
  - `max_tokens: 500`, `temperature: 0.1`, `timeout: 15s`
  - Fallback: ошибки Claude → `AI_UNAVAILABLE` ответ, не исключение наружу
- [x] `AiAssistantController.php`:
  - Валидация: `question` ≤ 500 символов, `locale` ∈ {ru, en}
  - Rate limit строго по `member_id` (`ai-assistant:{member_id}`), 10 req/min
  - **Проверка feature flag `ai_assistant` на backend (не только фронт)** → 403 при отключённом
- [x] `config/services.php` (или Calculator config): `anthropic.model` из env `ANTHROPIC_MODEL`
- [x] Роут `POST /api/cabinet/assistant/ask` в `api.php` под `telegram.auth`
- [x] Feature flag `ai_assistant` в `FeatureFlagSeeder` (deny-by-default)
- [x] `.env.example`: `ANTHROPIC_API_KEY=` + `ANTHROPIC_MODEL=claude-haiku-4-5`

### Шаг 3 — Frontend

- [x] `src/views/miniapp/Assistant.js`:
  - Suggested questions (6 вопросов, самодостаточные формулировки — не требуют контекста предыдущих)
  - Input + history (память только в React state, сессионная)
  - **Подсказка пользователю**: «Ассистент не помнит предыдущие вопросы — формулируйте каждый вопрос полностью»
  - Spin + fallback-текст при `AI_UNAVAILABLE`
  - Aurora palette
- [x] `mmAssistantAsk(question, locale)` в `api.js`
- [x] `src/views/miniapp/tabs/assistant.tab.js` — регистрация таба (иконка RobotOutlined, флаг `ai_assistant`)
- [x] Добавить `assistantTab` в `registry.js` (blockCTabs)
- [x] i18n-ключи `assistant.*` в `src/locales/ru/translation.json` и `en/translation.json`

---

## Порядок

KB-файлы → Backend (Service → Controller → Route → Seeder) → Frontend (api.js → Assistant.js → tab → registry → i18n) → Ревью + тест

---

## Ключевые решения

| Вопрос | Решение |
|---|---|
| Где хранить KB? | `resources/knowledge-base/` — бакается в Docker-образ, `resource_path()` |
| RAG? | Нет — KB загружаем файлами целиком в system prompt |
| Rate limit | 10 req/min по `member_id` + backend feature flag check |
| История сессии | Только в памяти браузера (stateless backend) |
| Locale | Поле `locale` в запросе (ru/en), KB каноническая на RU |
| User context | Только rank + package (без balance — PII наружу) |
| Guardrails | System prompt: только KB, no hallucinations, no income promises |
| Модель | `ANTHROPIC_MODEL` в env/config, не хардкод |
| Ошибки Claude | Fallback → `AI_UNAVAILABLE`, не 500 |

---

## Тесты (AiAssistantControllerTest)

- Unauthenticated → 401
- Feature flag disabled → 403
- Invalid locale → 422; question >500 символов → 422
- Rate limit exceeded (11-й запрос) → 429
- Claude API error → 200 `{code: AI_UNAVAILABLE}` (не 500)
- Success: мокаем Http, проверяем 200 с `answer`

---
---

# [АРХИВ] Предыдущие планы (все завершены)

# План: Лид-окно + изменяемый спонсор + личные рефералы — Гейт 2 (АКТИВНЫЙ)

**ТЗ:** `docs/specs/2026-06-23-lead-window-changeable-sponsor.md` (Гейт 1 утверждён).
Замок спонсора = подтверждённая оплата. **Движок `Modules/Calculator` не трогаем** (только вход).

## A. Архитектурное решение (итог разведки)

### A1. Лид — отдельная таблица `leads`, ВНЕ бинар-дерева
Member с `parent_id=NULL` невозможен для лида: partial-unique `members_single_root` допускает лишь
один корень. Лид не должен занимать слот (иначе спилловер ставит под него чужих → несовместимо с
«лид удаляемый/переносимый»). → **новая таблица `leads`**: `id`, `telegram_id` (unique),
`telegram_username`, `name`, `language`, `sponsor_id` (FK members, nullOnDelete) — замок-pending
спонсор (будущий личный реферал), `expires_at`, `timestamps`. Без `ref_code` (лид не рекрутирует),
без позиции в дереве.

### A2. Member создаётся только при подтверждённой оплате (промоушн)
Текущая постановка в дерево (`MiniAppAuth → registerTelegram → place`) при первом заходе — **убирается**
для Telegram-пути. Member появляется в `OrderService::markPaid` (внутри платёжной транзакции):
`place()` нового Member под `lead.sponsor_id` → backfill `order.member_id` → удалить лид → `activate()`
(ставит `status=active`, пересчёт). Атомарно. `registerTelegram`/`place` сохраняются для owner-bootstrap
(`AuthController`) и сидов/тестов.

### A3. Заказы/платежи принадлежат лиду до оплаты
`orders.member_id` и `payments.member_id` → **nullable**; добавить `orders.lead_id`, `payments.lead_id`
(nullable FK leads, nullOnDelete). Существующие строки (members) не ломаются; на промоушне backfill.

### A4. Идентичность запроса: member ИЛИ lead
`telegram.auth` middleware резолвит по `telegram_id`: (1) есть Member → attach `member`, `start_param`
игнор (спонсор замкнут); (2) есть Lead → attach `lead`, при `start_param` с другим валидным спонсором
в окне → перепривязка last-click-wins; протухший → пересоздать; (3) нет → создать Lead из `start_param`
(нужен валидный спонсор), без `start_param` → `need_referral`. Middleware кладёт оба атрибута (nullable);
member-only эндпоинты — guard (нет member → 4xx «активируйте пакет»); `ownerBootstrap` только при member.

### A5. Личное vs бинар (исправление дисплея)
`sponsor_id` = личный реферал (любая глубина); `parent_id/position/path` = бинар-команда.
`rank-progress.personal_count` уже корректен. Баг — фронтовый `tree.children.length` (≤2). Добавляем
эндпоинт списка личных рефералов + глубину относительно меня (ltree `nlevel`).

### A6. Совместимость с прод-данными
Существующие `status=registered` Member'ы остаются в дереве — НЕ конвертируем ретроспективно. Новая
модель — только для новых заходов. Root/сиды не трогаем.

## Статус
- [x] Гейт 1–4 — ЗАВЕРШЕНО, В ПРОДЕ (PR#15)

---

# [АРХИВ] Фаза 4 — Commerce и платежи (модель A, TON/USDT)

## Статус
- [x] S1–S9 backend + F0–F8 frontend — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000020+)

---

# [АРХИВ] Редизайн Mini App Aurora

## Статус
- [x] R1–R7 — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000028)

---

# [АРХИВ] Блок C (7 фич)

## Статус
- [x] C1–C7 — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000027, 2026-06-23)

---

# [АРХИВ] Веб-админ-панель (admin.izigo.adarasoft.com)

## Статус
- [x] MVP — ЗАВЕРШЕНО, В ПРОДЕ (rev 0000016+, 2026-06-22)

---

# [АРХИВ] Фаза 3 — Финансовое ядро

## Статус
- [x] Шаги 1–6 — ЗАВЕРШЕНО, В ПРОДЕ

---

# [АРХИВ] Telegram-only авторизация

## Статус
- [x] A1–A5 — ЗАВЕРШЕНО, В ПРОДЕ

---

# [АРХИВ] Фаза 1 / цикл 1 — доменное ядро (PV)

## Статус
- [x] ЗАВЕРШЕНО (ветка chore/phase-0-foundation)
