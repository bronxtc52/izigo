# Runbook: включить боевой приём оплаты TON Pay (USDT в сети TON)

**Статус на 2026-06-21.** Backend commerce (Фаза 4) и фронт checkout (F0–F3) готовы, но боевой
приём **не работает end-to-end** по двум причинам: (1) не дописан on-chain парсинг суммы на бэке
(заглушка), (2) не заведены секреты/параметры TON. Этот документ — что именно нужно, какие данные/
токены и откуда их взять, чтобы приём заработал.

Поток оплаты: партнёр создаёт заказ → бэк выдаёт инвойс `{merchant_address, memo=pay:{id}, amount}`
→ фронт через TonConnect шлёт jetton-transfer USDT на наш адрес с `comment=memo` → бэк опросом TON API
находит входящую транзакцию по memo+сумме → помечает `paid` → активация. Процессора нет (non-custodial),
webhook'а нет — только поллинг (`POST /cabinet/payments/{id}/check` и крон `commerce:tonpay-poll`).

---

## 0. Боевой парсинг суммы — РЕАЛИЗОВАН (2026-06-21), осталась live-сверка на тестнете

Блокер №1 снят. `Modules/Calculator/Services/Payment/TonPayGateway.php` переписан на **toncenter v3
`/api/v3/jetton/transfers`** (структурированные jetton-переводы) вместо ручного парсинга v2:

- `pollStatus()` опрашивает `?owner_address=<merchant>&jetton_master=<USDT>&direction=in&limit=100`,
  заголовок `X-Api-Key`. Для каждого перевода: пропуск `transaction_aborted`; **точное** сравнение memo
  по `forward_payload` (декод текст/hex/base64 целиком — без подстрок, иначе `pay:5` ловил бы `pay:55`);
  сумма `amount` (мин. единицы, decimals=6) **>= ожидаемой** (`amountCents × 10⁴`) → `paid`.
- Денежная семантика: переплату принимаем; недоплату/несовпадение НЕ финализируем как `failed`
  (вернётся `pending` — ждём верный/до-перевод, чтобы не потерять реально пришедшие средства).
- Тесты: `TonPayParsingTest` (10, Http::fake) + регрессия `TonPayPollTest`/commerce — зелёные.
- Прошло 2 раунда независимого ревью (закрыты P0: memo-коллизия, терминальный failed, переплата).

**Что осталось `NEEDS-LIVE-VERIFY` (сужено) — сверить на тестнете с живым USDT-джеттоном:**
- Реальная форма поля `forward_payload` у toncenter v3 (hex / base64 / структура) и формат `amount`
  (строка целого) — код обрабатывает вероятные представления, но живой ответ не видели.
- Глубина подтверждений; политика переплаты (сейчас «принять»).
- На фронте: `forward_ton_amount` (0.02 TON) и газ (0.1 TON) в `src/views/miniapp/tonPay.js`.
- **Авто-экспирация pending** (TTL) — НЕ реализована: платёж без подходящего перевода висит бессрочно.
  Отдельный TODO (cron, переводящий старые pending → expired).

> До live-сверки фронт и бизнес-логику гоняем против `FakeTonPayGateway` (см. §4).

---

## 1. Секреты в Azure Key Vault (бэкенд)

Источник правды — **Key Vault** (`kv-bronxtc-dev`), в ACA инжектятся env через `keyvaultref`.
В код/plain `.env` НЕ кладём. Имя секрета: `izigo--<env>--<KEY>` (env ∈ {beta, prod}).

| Env-переменная | Секрет в KV | Что это | Откуда взять |
|---|---|---|---|
| `TON_MERCHANT_ADDRESS` | `izigo--<env>--TON-MERCHANT-ADDRESS` | Наш TON-адрес-получатель (owner) USDT | Создать отдельный TON-кошелёк под приём (Tonkeeper/Tonhub/web) → взять его адрес. Для prod — выделенный кошелёк, не личный. |
| `TON_API_KEY` | `izigo--<env>--TON-API-KEY` | Ключ к TON API (toncenter) для опроса сети | Telegram-бот **@tonapibot** (mainnet) / **@tontestnetapibot** (testnet) → `/get_api_key`. Бесплатный, rate-limited. |

Параметр не-секретный, но env (можно plain, без KV):

| Env | Значение | Примечание |
|---|---|---|
| `PAYMENT_GATEWAY` | `ton_pay` | Боевой драйвер приёма. Для тестов — `ton_pay_fake`. |
| `COMMERCE_CURRENCY` | `USDT` | Учётная валюта. |
| `TON_API_V3_BASE_URL` | mainnet: `https://toncenter.com/api/v3` · testnet: `https://testnet.toncenter.com/api/v3` | База для опроса приёма (v3 jetton/transfers). Должна совпадать с сетью кошелька. |
| `TON_USDT_JETTON_MASTER` | mainnet USDT: `EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs` · testnet — адрес тест-джеттона | Мастер-контракт USDT (нужен для фильтра опроса). Совпадает с фронтовым `NEXT_PUBLIC_USDT_JETTON_MASTER`. |
| `TON_API_BASE_URL` | mainnet: `https://toncenter.com/api/v2` (выплаты) | Используется драйвером **выплат** (не приёма). |

> Приватный ключ для **приёма НЕ нужен** (деньги идут напрямую на адрес). Приватный ключ нужен только
> для **выплат** (`TON_PAYOUT_WALLET_KEY` — отдельная задача, не про приём).

Завести секрет (пример):
```bash
az keyvault secret set --vault-name kv-bronxtc-dev \
  --name izigo--beta--TON-MERCHANT-ADDRESS --value "<TON-адрес>"
az keyvault secret set --vault-name kv-bronxtc-dev \
  --name izigo--beta--TON-API-KEY --value "<api-key-из-бота>"
```
Привязать к ACA — через `keyvaultref` (см. kb-azure-aca / DEPLOY.md), затем перезапустить ревизию.

---

## 2. Публичные параметры фронта (НЕ секреты)

Это `NEXT_PUBLIC_*` — попадают в бандл, поэтому **только публичные значения, без ключей/токенов**
(правило «секреты только в Key Vault»). Задаются как env билда фронта (ACA env / CI), не в KV.

| Env (NEXT_PUBLIC_) | Значение | Что это / откуда |
|---|---|---|
| `NEXT_PUBLIC_TON_NETWORK` | `mainnet` или `testnet` | Сеть. Должна совпадать с кошельком и `TON_API_BASE_URL` бэка. |
| `NEXT_PUBLIC_USDT_JETTON_MASTER` | mainnet USDT (Tether): `EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs` · testnet: адрес тестового USDT-джеттона | Мастер-контракт USDT. Для testnet задеплоить/взять тестовый jetton. |
| `NEXT_PUBLIC_TON_RPC` | keyless RPC, напр. `https://toncenter.com/api/v2/jsonRPC` (mainnet) / `https://testnet.toncenter.com/api/v2/jsonRPC` (testnet) | Для резолва jetton-кошелька отправителя. **Keyless/публичный или свой прокси** — API-ключ сюда НЕ кладём. При высоких лимитах — поднять прокси на бэке. |
| `NEXT_PUBLIC_SERVER_FRONT_URL` | https-домен Mini App (прод) | Уже используется; от него строится TonConnect-манифест `/tonconnect-manifest.json`. |

Плюс положить файл **`mh-calc-frontend-main/public/tonconnect-icon.png`** (180×180) — иконка dApp в
кошельке. Без неё строгие кошельки покажут заглушку (на подключение не критично).

---

## 3. Где что в коде (для ориентира)

- Конфиг бэка: `Modules/Calculator/Config/config.php` (ключи `payment_gateway`, `ton_merchant_address`,
  `ton_api_key`, `ton_api_base_url`).
- Боевой драйвер: `Modules/Calculator/Services/Payment/TonPayGateway.php` (см. §0).
- Тест-драйвер: `Modules/Calculator/Services/Payment/FakeTonPayGateway.php`.
- Инвойс/поллинг: `Modules/Calculator/Services/PaymentService.php`, крон `commerce:tonpay-poll`.
- Фронт: `src/views/miniapp/tonPay.js` (сборка/отправка перевода), `TonPayCheckout.js`,
  `src/app/tonconnect-manifest.json/route.js`, `.env.example` (TON-параметры).

---

## 4. Порядок включения

1. **Тестнет, без денег.** `PAYMENT_GATEWAY=ton_pay_fake` (бэк) + клик-тест фронта: каталог → купить →
   checkout → статус `paid` (фейк подтверждает по своему реестру). Проверяет UI/поллинг/активацию.
2. **Выверить парсинг на тестнете** (§0 реализован): реальная форма `forward_payload`/`amount` у
   toncenter v3, `forward_ton_amount`/газ на фронте. Снять остаток `NEEDS-LIVE-VERIFY`.
3. **Тестнет, боевой драйвер.** Завести testnet-секреты (§1) + `NEXT_PUBLIC_*` на testnet (§2),
   `PAYMENT_GATEWAY=ton_pay`. Реальный перевод тестового USDT с тестового кошелька → платёж становится
   `paid`, заказ активируется. Выверить `forward_ton_amount`/газ.
4. **Mainnet (prod).** Те же секреты/параметры на mainnet-значения, выделенный merchant-кошелёк,
   `kyc_threshold_cents` по политике. Контрольный платёж малой суммой.

## 5. Смоук-проверка

- `POST /cabinet/orders/{id}/pay` возвращает `merchant_address` (не пустой) и `memo=pay:{id}`.
- После реального перевода `POST /cabinet/payments/{id}/check` → `{payment_status:"paid"}` в течение
  минуты (или по крону `commerce:tonpay-poll`).
- Заказ перешёл в `paid`, прошла активация тарифа (бонусы/ledger). Идемпотентно — повтор не задваивает.

---

## Чек-лист «приём готов к prod»

- [ ] `amountMatches()` + jetton-парсинг реализованы и проверены на testnet (снят NEEDS-LIVE-VERIFY).
- [ ] `forward_ton_amount`/газ на фронте выверены на testnet.
- [ ] KV: `izigo--prod--TON-MERCHANT-ADDRESS`, `izigo--prod--TON-API-KEY` заведены и привязаны к ACA.
- [ ] env бэка: `PAYMENT_GATEWAY=ton_pay`, `TON_API_BASE_URL`=mainnet, `COMMERCE_CURRENCY=USDT`.
- [ ] env фронта: `NEXT_PUBLIC_TON_NETWORK=mainnet`, `NEXT_PUBLIC_USDT_JETTON_MASTER`(mainnet USDT),
      `NEXT_PUBLIC_TON_RPC`(keyless), `NEXT_PUBLIC_SERVER_FRONT_URL`(прод-домен).
- [ ] `public/tonconnect-icon.png` (180×180) на месте, манифест отдаётся по https.
- [ ] Контрольный платёж малой суммой на mainnet прошёл end-to-end.

Связано: `docs/specs/2026-06-21-phase4-commerce-payments.md`, `plan.md` (раздел K), память
`izigo-phase4-progress`, `izigo-prod-map`. Секреты/KV — kb-secrets-keyvault; ACA-инжект — kb-azure-aca.
