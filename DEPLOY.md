# IziGo — деплой (Фаза 0, baseline в Azure Container Apps)

Интерим-деплой текущего калькулятора для проверки пайплайна. Прод-уровень (php-fpm,
keyvaultref-секреты, домены, multi-replica миграции через ACA Job) — Фаза 1-2.

Конвенции: RG `rg-izigo-beta-neu`, регион `northeurope`, теги `project=izigo,env=beta,owner=bronxtc52`.
Секреты — только в Key Vault (`kv-bronxtc-dev`), не в коде/git.

## 0. Предпосылки (твои шаги, заблокированы для агента)
- **GitHub**: создать `bronxtc52/izigo` (private), запушить ветку:
  ```bash
  gh repo create bronxtc52/izigo --private
  git remote add origin https://github.com/bronxtc52/izigo.git
  git push -u origin chore/phase-0-foundation   # затем PR → main
  ```
- **Sentry**: на `sentry.adarasoft.com` (org `sentry`) создать проект `izigo`, авто-токен
  (scopes `org:read`+`project:releases`), положить в KV:
  ```bash
  az keyvault secret set --vault-name kv-bronxtc-dev --name izigo--beta--SENTRY-DSN --value "<dsn>"
  ```

## 1. Resource group
```bash
az group create -n rg-izigo-beta-neu -l northeurope \
  --tags project=izigo env=beta owner=bronxtc52
```

## 2. ACR + Log Analytics + ACA environment
```bash
az acr create -g rg-izigo-beta-neu -n izigoacr --sku Basic --admin-enabled false
az monitor log-analytics workspace create -g rg-izigo-beta-neu -n log-izigo
LAW_ID=$(az monitor log-analytics workspace show -g rg-izigo-beta-neu -n log-izigo --query customerId -o tsv)
LAW_KEY=$(az monitor log-analytics workspace get-shared-keys -g rg-izigo-beta-neu -n log-izigo --query primarySharedKey -o tsv)
az containerapp env create -g rg-izigo-beta-neu -n cae-izigo -l northeurope \
  --logs-workspace-id "$LAW_ID" --logs-workspace-key "$LAW_KEY"
```

## 3. PostgreSQL Flexible (B1ms) + база
```bash
PGPASS=$(openssl rand -base64 24)
az keyvault secret set --vault-name kv-bronxtc-dev --name izigo--beta--DB-PASSWORD --value "$PGPASS"
az postgres flexible-server create -g rg-izigo-beta-neu -n izigo-pg \
  --location northeurope --tier Burstable --sku-name Standard_B1ms \
  --version 16 --storage-size 32 --admin-user izigo --admin-password "$PGPASS" \
  --public-access 0.0.0.0   # разрешить Azure-сервисы; сузить позже
az postgres flexible-server db create -g rg-izigo-beta-neu -s izigo-pg -d izigo
```

## 4. Managed identity + AcrPull
```bash
az identity create -g rg-izigo-beta-neu -n id-izigo
ID_PRINCIPAL=$(az identity show -g rg-izigo-beta-neu -n id-izigo --query principalId -o tsv)
ID_RES=$(az identity show -g rg-izigo-beta-neu -n id-izigo --query id -o tsv)
ACR_ID=$(az acr show -n izigoacr --query id -o tsv)
az role assignment create --assignee "$ID_PRINCIPAL" --role AcrPull --scope "$ACR_ID"
# доступ к Key Vault (секреты):
az keyvault set-policy --name kv-bronxtc-dev --object-id "$ID_PRINCIPAL" --secret-permissions get list
```

## 5. APP_KEY в KV
```bash
APP_KEY=$(cd mh-calc-backend-main && php artisan key:generate --show)
az keyvault secret set --vault-name kv-bronxtc-dev --name izigo--beta--APP-KEY --value "$APP_KEY"
```

## 6. Container Apps (backend + frontend)
Образы соберёт CI (шаг 8); для первого создания можно временно использовать публичный
hello-image, затем CI обновит. Backend env (DB_*, APP_KEY, SENTRY DSN) — через секреты
(keyvaultref с identity id-izigo) или `--set-env-vars` на время Фазы 0.
```bash
# пример backend (порт 8080, external ingress); секреты через keyvaultref — см. kb-azure-aca
az containerapp create -g rg-izigo-beta-neu -n ca-izigo-backend --environment cae-izigo \
  --user-assigned "$ID_RES" --ingress external --target-port 8080 \
  --image mcr.microsoft.com/azuredocs/containerapps-helloworld:latest \
  --secrets db-password=keyvaultref:https://kv-bronxtc-dev.vault.azure.net/secrets/izigo--beta--DB-PASSWORD,identityref:$ID_RES \
            app-key=keyvaultref:https://kv-bronxtc-dev.vault.azure.net/secrets/izigo--beta--APP-KEY,identityref:$ID_RES \
            sentry-dsn=keyvaultref:https://kv-bronxtc-dev.vault.azure.net/secrets/izigo--beta--SENTRY-DSN,identityref:$ID_RES \
  --env-vars APP_ENV=production APP_DEBUG=false \
             DB_CONNECTION=pgsql DB_HOST=izigo-pg.postgres.database.azure.com DB_PORT=5432 \
             DB_DATABASE=izigo DB_USERNAME=izigo DB_PASSWORD=secretref:db-password \
             APP_KEY=secretref:app-key SENTRY_LARAVEL_DSN=secretref:sentry-dsn \
             APP_URL=https://<backend-fqdn>

# frontend (порт 3000)
az containerapp create -g rg-izigo-beta-neu -n ca-izigo-frontend --environment cae-izigo \
  --user-assigned "$ID_RES" --ingress external --target-port 3000 \
  --image mcr.microsoft.com/azuredocs/containerapps-helloworld:latest
```

## 7. OIDC federated credential для GitHub Actions
```bash
APP_ID=$(az ad app create --display-name izigo-gh-oidc --query appId -o tsv)
az ad sp create --id "$APP_ID"
az role assignment create --assignee "$APP_ID" --role Contributor \
  --scope $(az group show -n rg-izigo-beta-neu --query id -o tsv)
az ad app federated-credential create --id "$APP_ID" --parameters '{
  "name":"izigo-main","issuer":"https://token.actions.githubusercontent.com",
  "subject":"repo:bronxtc52/izigo:ref:refs/heads/main","audiences":["api://AzureADTokenExchange"]}'
# GitHub secrets:
gh secret set AZURE_CLIENT_ID -b "$APP_ID" -R bronxtc52/izigo
gh secret set AZURE_TENANT_ID -b "$(az account show --query tenantId -o tsv)" -R bronxtc52/izigo
gh secret set AZURE_SUBSCRIPTION_ID -b "$(az account show --query id -o tsv)" -R bronxtc52/izigo
gh secret set ACR_NAME -b izigoacr -R bronxtc52/izigo
gh secret set RG_NAME -b rg-izigo-beta-neu -R bronxtc52/izigo
gh secret set BACKEND_APP -b ca-izigo-backend -R bronxtc52/izigo
gh secret set FRONTEND_APP -b ca-izigo-frontend -R bronxtc52/izigo
gh secret set BACKEND_URL -b "https://<backend-fqdn>" -R bronxtc52/izigo
gh secret set FRONTEND_URL -b "https://<frontend-fqdn>" -R bronxtc52/izigo
```

## 8. Деплой
Мердж в `main` → `.github/workflows/deploy.yml` соберёт образы в ACR и обновит ACA.
Или вручную: `gh workflow run "Deploy IziGo to Azure Container Apps"`.

## 9. Observability / watchdog
- Добавить `rg-izigo-beta-neu` в `server-watchdog` (`AZURE_RESOURCE_GROUPS`).
- Azure Monitor алёрты ACA (CPU/RAM/доступность) — см. kb-azure-monitoring.
- Sentry: smoke-событие, релиз-тег = `github.sha` (best-effort).

## 10. Кастомные домены (временные)
Фронт `ca-izigo-frontend` привязан к двум поддоменам `adarasoft.com` (зона в RG `dns-rg`):
- `izigo.adarasoft.com` — основной фронт.
- `admin.izigo.adarasoft.com` — placeholder под будущую веб-админку; **сейчас отдаёт
  тот же фронт** (отдельного admin-приложения нет, админка — вкладка `MiniAppAdmin`
  внутри Mini App).

Оба — поддомены → валидация по CNAME + asuid TXT, managed-сертификат бесплатный.
`VID` = `customDomainVerificationId` приложения
(`az containerapp show -n ca-izigo-frontend -g rg-izigo-beta-neu --query properties.customDomainVerificationId -o tsv`).
```bash
FQDN=ca-izigo-frontend.livelycoast-2b4dcf83.northeurope.azurecontainerapps.io
VID=<customDomainVerificationId>
for SUB in izigo admin.izigo; do
  # DNS (зона adarasoft.com в dns-rg)
  az network dns record-set cname set-record -g dns-rg -z adarasoft.com \
    --record-set-name "$SUB" --cname "$FQDN"
  az network dns record-set txt add-record -g dns-rg -z adarasoft.com \
    --record-set-name "asuid.$SUB" --value "$VID"
  # привязка + managed cert (выпуск до ~20 мин)
  H="$SUB.adarasoft.com"
  az containerapp hostname add  -n ca-izigo-frontend -g rg-izigo-beta-neu --hostname "$H"
  az containerapp hostname bind -n ca-izigo-frontend -g rg-izigo-beta-neu \
    --hostname "$H" --environment cae-izigo --validation-method CNAME
done
```
Проверка: `az containerapp hostname list -n ca-izigo-frontend -g rg-izigo-beta-neu -o table`
(оба `SniEnabled`), `curl -sI https://izigo.adarasoft.com` → `HTTP/2 200`.

> ⚠️ Привязка живёт только в ACA (IaC/Bicep нет) — при пересоздании приложения повторить.
> `NEXT_PUBLIC_SERVER_FRONT_URL` и TonConnect-манифест запечены при build на старый ACA-FQDN
> (см. §7, secret `FRONTEND_URL`) — при официальном переключении продукта на новый домен
> нужна **пересборка фронта** с новым build-arg, не только привязка домена.

## 11. Веб-админка (admin.izigo.adarasoft.com)
Веб-админка живёт в том же фронт-аппе (роутинг по хосту, `src/middleware.js`); вход —
Telegram Login Widget → Sanctum-токен. Чек-лист выката:

1. **Build-arg фронта** — уже в CI: `NEXT_PUBLIC_TG_BOT_USERNAME=Izigopro_mlm_bot`
   (`deploy.yml` + `Dockerfile`). Публичный параметр (имя бота, не токен). В коде есть fallback.
2. **BotFather `/setdomain`** (ОБЯЗАТЕЛЬНО, иначе виджет не отрисуется): в @BotFather →
   `/setdomain` → выбрать `@Izigopro_mlm_bot` → отправить `admin.izigo.adarasoft.com`.
   Это настройка BotFather, **не** метод Bot API.
3. **Backend env** — без изменений: Login Widget валидируется тем же `TELEGRAM_BOT_TOKEN`,
   что и initData; owner-бутстрап — из `OWNER_TELEGRAM_IDS` (оба уже заданы для Mini App).
   Проверить, что владельцы перечислены в `OWNER_TELEGRAM_IDS`, иначе вход вернёт 403 `not_admin`.
4. **Миграции** — прогонятся на деплое (`docker/start.sh` → `migrate --force`):
   `admin_audit_log` + Sanctum `personal_access_tokens` (vendor, грузится самим Sanctum).
5. **TTL токена** (опц.) — env `WEB_ADMIN_TOKEN_TTL_MINUTES` (дефолт 720). Можно сузить для безопасности.
6. **Домен на ACA** — уже привязан (§10), cert green. Админка отдаётся на admin.izigo.* тем же фронтом.
7. **BFF-cookie (t1-admin-cookie-auth)** — Sanctum-токен админки больше не живёт в
   localStorage: вход и все admin-запросы идут через Next BFF-proxy (`/api/v1/...` на
   admin-хосте), токен запечатан в httpOnly-cookie `izigo_admin_s`. Шаги выката
   (env на ACA durable — задаются один раз из az-сессии владельца, CI их не трогает):
   - Секреты в KV: `izigo--beta--ADMIN-PROXY-KEY` и `izigo--beta--ADMIN-COOKIE-SECRET`
     (случайные строки ≥32 байт, `openssl rand -base64 32`).
   - **Фронт** `ca-izigo-frontend`: env `ADMIN_COOKIE_SECRET`, `ADMIN_PROXY_KEY`
     (keyvaultref/secret), `BACKEND_INTERNAL_URL=<URL бэка>`; опц. `WEB_ADMIN_TOKEN_TTL_MINUTES`
     в синхроне с бэком.
   - **Бэк** `ca-izigo-backend`: env `ADMIN_PROXY_KEY` (тот же секрет) + `ADMIN_BFF_ENABLED=true`
     (амендмент A-t1: выдача admin-токена только с валидным `X-Admin-Proxy-Key`).
   - **Одноразовый revoke** старых токенов (мгновенно закрывает XSS-окно, админы перелогинятся):
     `php artisan calculator:revoke-web-admin-tokens` из контейнера бэка. Предупредить
     админов о re-login в отчёте выката.
   - **Kill-switch**: `ADMIN_BFF_ENABLED=false` на бэке → прокси-key не форсится (старый
     прямой Bearer-путь снова работает — для отката вместе с предыдущим образом фронта).
   - **Приёмка**: после логина в localStorage НЕТ `izigo_admin_token`; `document.cookie`
     НЕ показывает `izigo_admin_s` (httpOnly); ответ логина БЕЗ поля `token`; прямой
     `POST /api/v1/auth/telegram-login` на бэк без `X-Admin-Proxy-Key` → 403; пути
     `/api/v1/*` на `izigo.adarasoft.com` → 404; смоук Mini App (initData, каталог,
     оплата) — без регрессий (CORS бэка не менялся).

> Смена бренд-домена izigo.* (миграция самого продукта на adarasoft) — отдельное решение;
> для админки достаточно поддомена admin.* (host-routing работает на любом хосте).

## Известные ограничения Фазы 0 (доработать в Фазе 1)
- Backend на `artisan serve` (не прод) → php-fpm/nginx или Octane.
- Миграции на старте контейнера (single-replica) → вынести в ACA Job при multi-replica.
- Postgres public-access 0.0.0.0 → сузить до VNet/private endpoint.
