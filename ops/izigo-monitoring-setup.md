# IziGo — подключение прода к мониторингу (runbook, ручные шаги)

Закрывает блокер **B-4** аудита (`docs/reviews/2026-07-02-commercial-launch-readiness-audit.md`):
прод IziGo (RG `rg-izigo-beta-neu`) целиком вне мониторинга — нет Azure Monitor алёртов и RG не в
`server-watchdog`. Для платформы с TON-платежами тихая остановка подтверждения оплат недопустима.

> ⚠️ Всё ниже выполняет **человек из авторизованной сессии** (`az login` под аккаунтом с правами
> на подписку). Managed identity mh-central **не видит** `rg-izigo-beta-neu` — из watchdog/агента
> это не сделать. Прод-действия — красная зона.

Прод-факты: SUB `c05debcb-f65a-4aee-9d1e-0f598536a024`, RG `rg-izigo-beta-neu` (northeurope),
ACA-env `cae-izigo`, apps `ca-izigo-backend` / `ca-izigo-frontend` / `ca-izigo-bot`, PG `izigo-pg-beta`.

---

## 1. Silent Azure Monitor алёрты

Скрипт `ops/alerts-izigo.sh` создаёт стандартный набор (restarts `max`+auto-mitigate, cpu-high,
memory-high, no-replicas для always-on) + scheduled-query (aca-system-failures, console-critical-errors)
+ daily-cap 2 GB. Всё silent (без action group), теги `project=izigo/env=beta/owner=bronxtc52`.

Перед запуском:

```bash
# a) узнать имя Log Analytics workspace RG (нужно скрипту)
az monitor log-analytics workspace list -g rg-izigo-beta-neu --query "[].name" -o tsv

# b) (желательно) сверить фактический scale и лимиты контейнеров, поправить пороги/ALWAYS_ON в скрипте
az containerapp show -n ca-izigo-backend  -g rg-izigo-beta-neu --query "properties.template.scale" -o json
az containerapp show -n ca-izigo-frontend -g rg-izigo-beta-neu --query "properties.template.scale" -o json
az containerapp show -n ca-izigo-bot      -g rg-izigo-beta-neu --query "properties.template.containers[0].resources" -o json
```

Запуск (передать имя workspace через переменную, либо вписать в `WS_NAME` в скрипте):

```bash
cd ~/projects/binar-mlm     # рабочая копия репо
IZIGO_LAW_NAME=<имя-workspace-из-шага-a> bash ops/alerts-izigo.sh
```

Проверка:

```bash
az monitor metrics alert list -g rg-izigo-beta-neu -o table
az monitor scheduled-query list -g rg-izigo-beta-neu -o table
```

---

## 2. read-роли MI mh-central на `rg-izigo-beta-neu`

Чтобы `server-watchdog` (system-assigned managed identity VM mh-central) читал health/логи/алёрты
этого RG. **Только read-роли, БЕЗ кастомной роли `ACA Remediator`** — ремедиации по izigo нет
(как у `rg-contactmlm` / `rg-kaizen-app`).

```bash
SUB="c05debcb-f65a-4aee-9d1e-0f598536a024"
RG_SCOPE="/subscriptions/${SUB}/resourceGroups/rg-izigo-beta-neu"

# principalId system-assigned MI VM mh-central (RG-MH-CENTRAL):
MI_PRINCIPAL_ID="$(az vm show -g RG-MH-CENTRAL -n mh-central \
  --query identity.principalId -o tsv)"

for ROLE in "Reader" "Monitoring Reader" "Log Analytics Reader"; do
  az role assignment create --assignee-object-id "$MI_PRINCIPAL_ID" \
    --assignee-principal-type ServicePrincipal \
    --role "$ROLE" --scope "$RG_SCOPE"
done
```

Проверка:

```bash
az role assignment list --assignee "$MI_PRINCIPAL_ID" --scope "$RG_SCOPE" -o table
```

---

## 3. Добавить RG в `server-watchdog`

Два места (host-env для рантайма + upstream в репо для истории/дефолта).

### 3a. Host-env на mh-central (даёт эффект сразу)

Файл `/etc/server-watchdog/server-watchdog.env` — добавить RG в `AZURE_RESOURCE_GROUPS`:

```bash
# было (пример):
# AZURE_RESOURCE_GROUPS=rg-forecast-mh,rg-aidos-football-prod-weu,rg-mh-stat-prod-weu,rg-mhg-lms-prod,horkos,rg-avicenna-prod,rg-contactmlm,rg-kaizen-app
# стало — добавить ,rg-izigo-beta-neu в конец
sudo sed -i 's/\(AZURE_RESOURCE_GROUPS=.*\)/\1,rg-izigo-beta-neu/' /etc/server-watchdog/server-watchdog.env
```

> Если `AZURE_RESOURCE_GROUPS` в host-env НЕ задан, watchdog берёт дефолт из кода
> (`DEFAULT_AZURE_RESOURCE_GROUPS`, см. 3b) — тогда достаточно правки в репо + `git pull` + пересборка.

### 3b. Upstream-правка в репо `~/projects/server-watchdog`

Единый источник истины по RG и их workspace'ам — **`src/collectors/azure.ts`**, объект
`RG_WORKSPACE` (ключи = RG, из них выводится `DEFAULT_AZURE_RESOURCE_GROUPS`). Добавить строку:

```ts
// src/collectors/azure.ts, в const RG_WORKSPACE
'rg-izigo-beta-neu': '<workspace-customerId-GUID>',   // izigo прод (beta)
```

`<workspace-customerId-GUID>` — это `customerId` того же Log Analytics workspace:

```bash
az monitor log-analytics workspace show -g rg-izigo-beta-neu -n <имя-workspace> \
  --query customerId -o tsv
```

Также обновить закомментированный пример в **`.env.example`** (строка `# AZURE_RESOURCE_GROUPS=...`)
— дописать `,rg-izigo-beta-neu`, чтобы дефолт-список в доке не отставал.

После правки: `npm run build` (репо на TS, рантайм из `dist/`) и рестарт по крону/юниту watchdog.
Оформить PR в `bronxtc52/server-watchdog` (это отдельный репо, не binar-mlm).

Проверка (следующий прогон дайджеста должен включить izigo):

```bash
cd ~/projects/server-watchdog && npm run build && node dist/index.js   # разовый прогон
```

---

## 4. Health-эндпоинт бэкенда в HTTP-чек watchdog

Роут `GET /api/health` добавляется отдельным фиксом (**B-5 / M1**). Когда он в проде — завести
HTTP-чек в fleet-реестре watchdog `src/fleet/registry.json` (сервис izigo backend, URL
`https://ca-izigo-backend.livelycoast-2b4dcf83.northeurope.azurecontainerapps.io/api/health`,
ожидаемый `200`). Это даёт «снаружи»-проверку живости бэка независимо от Azure-метрик.

Смоук вручную (после деплоя B-5):

```bash
curl -fsS https://ca-izigo-backend.livelycoast-2b4dcf83.northeurope.azurecontainerapps.io/api/health
```

### ACA startup/liveness/readiness probes (настраиваются ОТДЕЛЬНО) — скрипт `ops/izigo-aca-probes.sh`

Метрики ACA (RestartCount и т.п.) отражают перезапуски только если у контейнера есть probe.
Готовый идемпотентный скрипт **`ops/izigo-aca-probes.sh`** вешает на `ca-izigo-backend` ТРИ пробы на
**targetPort бэка = 8080** (Dockerfile `EXPOSE 8080`, `docker/start.sh` → `artisan serve --port 8080`):

- **Startup** `httpGet /up`, `periodSeconds 5`, `failureThreshold 30` (~150с) — окно на
  `migrate` + сидеры + `scheduler:heartbeat` ДО открытия порта `artisan serve`. Пока Startup не
  прошёл, Liveness/Readiness не считаются → долгий старт не крэш-лупит контейнер.
- **Liveness** `httpGet /up`, `periodSeconds 30`, `failureThreshold 3` — «процесс жив», дёшево,
  БЕЗ БД. Намеренно **НЕ `/api/health`**: вставший планировщик отдаёт `/api/health` 503, но
  контейнер из-за этого убивать нельзя (крэш-луп) — это забота readiness.
- **Readiness** `httpGet /api/health`, `periodSeconds 15`, `failureThreshold 3` — БД + свежесть
  heartbeat планировщика; 503 выводит реплику из ротации ingress, а Liveness держит контейнер живым.

```bash
cd ~/projects/binar-mlm     # рабочая копия репо
bash ops/izigo-aca-probes.sh   # покажет текущий спек → применит пробы → выведет ревизии
```

> ⚠️ probe на backend, у которого `artisan serve` + `schedule:work` в одном процессе, ставить
> аккуратно: неверный порт/путь → ACA считает контейнер unhealthy → крэш-луп (см. грабли
> forecast-mh-app в корневом CLAUDE.md). Порт **8080** (не 8000!) — сверено с Dockerfile/start.sh.
> Скрипт сам печатает ревизии в конце; дополнительно:
> `az containerapp revision list -n ca-izigo-backend -g rg-izigo-beta-neu -o table`.

---

## Чек-лист (что человек выполняет руками)

- [ ] `az monitor log-analytics workspace list -g rg-izigo-beta-neu` → узнать имя workspace
- [ ] (свериться) scale/лимиты контейнеров → поправить `ALWAYS_ON` / пороги в `ops/alerts-izigo.sh`
- [ ] `IZIGO_LAW_NAME=<ws> bash ops/alerts-izigo.sh` → создать алёрты + daily-cap
- [ ] 3× `az role assignment create` (Reader / Monitoring Reader / Log Analytics Reader) на MI mh-central
- [ ] правка `/etc/server-watchdog/server-watchdog.env` (`AZURE_RESOURCE_GROUPS += rg-izigo-beta-neu`)
- [ ] PR в `bronxtc52/server-watchdog`: `RG_WORKSPACE` + `.env.example`, `npm run build`
- [ ] (после B-5) `/api/health` в `registry.json` watchdog
- [x] ACA startup/liveness/readiness probes на порт 8080 — артефакт `ops/izigo-aca-probes.sh` готов (**apply делает человек** из `az login`-сессии; MSI mh-central read-only на RG)
