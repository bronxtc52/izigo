# IziGo: бэкапы и восстановление Postgres (izigo-pg-beta)

Прод-БД: `izigo-pg-beta.postgres.database.azure.com` (rg-izigo-beta-neu, northeurope, PG16,
B1ms/32GB), база `izigo`, юзер `izigo`, пароль — KV `izigo--beta--DB-PASSWORD`, sslmode=require.

## Два слоя защиты

| Слой | Что даёт | Окно | RPO | RTO |
|---|---|---|---|---|
| **PITR** (встроенный Azure) | восстановление на любую минуту | 14 дней | ≤5 мин (WAL) | ~30–60 мин |
| **Logical-дамп** (cron mh-central, 03:30 UTC) | независимая кросс-регионная копия (westeurope) | 30 дней | ≤24 ч | ~30 мин |

⚠️ **Geo-redundant backup выключен и на живом сервере НЕ включается** (Azure позволяет только
при создании сервера). Потеря региона / удаление самого сервера (PITR-бэкапы умирают вместе
с ним) покрываются только logical-дампами. Это осознанный компромисс беты; при росте — новое
решение (пересоздание сервера с geo-redundant или read-реплика в другом регионе).

Скрипт: `ops/backup-izigo-pg.sh` (запускается из рабочей копии репо на mh-central).
Дампы: `mh-central:~/backups/izigo-pg/izigo-YYYYMMDD-HHMMSS.dump` (600/700, PII — наружу не отдавать).
Лог крона: `~/backups/izigo-pg/backup.log`. Формат — `pg_dump -Fc --no-owner --no-privileges`.
Дамп пишется в `.part` и получает боевое имя только после верификации `pg_restore --list` —
усечённый обрыв не притворяется валидным бэкапом.

## Выбор сценария

| Беда | Путь |
|---|---|
| Ошибочная миграция / массовый DELETE, замечено в пределах 14 дней | **A. PITR** на момент до аварии |
| Порча/потеря данных старше 14 дней | **B. Logical-дамп** нужной даты |
| Регион northeurope недоступен / сервер удалён | **B** в новый сервер (любой регион) |
| Нужно поштучно достать строки (не полный откат) | **A или B во временную БД** → выборочно перенести |

## A. PITR-восстановление

PITR всегда создаёт **новый** сервер — оригинал не трогается.

```bash
# 1. Восстановить на момент времени (UTC!) — новый сервер с тем же SKU (~10-15 мин)
az postgres flexible-server restore \
  -g rg-izigo-beta-neu --name izigo-pg-restored \
  --source-server izigo-pg-beta \
  --restore-time "2026-07-03T08:00:00Z"

# 2. Открыть себе доступ (правило Azure-services на новый сервер не переносится автоматом)
az postgres flexible-server firewall-rule create \
  -g rg-izigo-beta-neu --name izigo-pg-restored \
  --rule-name AllowAzureServices --start-ip-address 0.0.0.0 --end-ip-address 0.0.0.0

# 3. Верифицировать данные (см. «Верификация» ниже) на izigo-pg-restored
```

Дальше два пути:

- **A1. Repoint приложения** (быстрее, если откатываем всё): на `ca-izigo-backend`
  `az containerapp update … --set-env-vars DB_HOST=izigo-pg-restored.postgres.database.azure.com`
  (env durable, см. память izigo-aca-env-persists-deploy). Старый сервер оставить выключенным
  (`az postgres flexible-server stop`) на подумать, потом удалить; либо переименовать оба.
  ⚠️ Azure сам ЗАПУСКАЕТ остановленный Flexible Server через 7 дней — не удивляться ожившему
  серверу и строке в билле; решить судьбу старого раньше недели.
- **A2. Перелить данные назад** (если откатываем точечно): `pg_dump` с restored →
  `pg_restore` в оригинал (процедура B, источник — restored-сервер) → restored удалить.

💰 Восстановленный сервер тарифицируется как обычный B1ms — не забыть удалить после операции.

## B. Восстановление из logical-дампа

```bash
# 0. Если оригинального сервера нет — создать новый PG16 Flexible (B1ms/32GB), базу izigo,
#    роль izigo (пароль из KV), firewall Azure-services И РАЗРЕШИТЬ ltree (иначе restore
#    молча упадёт на CREATE EXTENSION — ядро бинар-дерева):
az postgres flexible-server parameter set -g <rg> --server-name <новый-сервер> \
  --name azure.extensions --value LTREE

# 1. Выбрать дамп
ls -lt ~/backups/izigo-pg/

# 2. Восстанавливать ВО ВРЕМЕННУЮ БД, не поверх живой
PGPASSWORD=$(az keyvault secret show --vault-name kv-bronxtc-dev \
  --name izigo--beta--DB-PASSWORD --query value -o tsv)
export PGPASSWORD
psql "host=izigo-pg-beta.postgres.database.azure.com dbname=postgres user=izigo sslmode=require" \
  -c "CREATE DATABASE izigo_restore"
pg_restore --no-owner --no-privileges -d "host=izigo-pg-beta.postgres.database.azure.com \
  dbname=izigo_restore user=izigo sslmode=require" ~/backups/izigo-pg/izigo-<дата>.dump

# 3. Верифицировать izigo_restore, затем либо выборочно перенести данные, либо подменить БД:
#    остановить трафик (min-replicas 0 на бэке) → убить остаточные сессии (иначе RENAME
#    упадёт «database is being accessed»):
#      SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='izigo';
#    → ALTER DATABASE izigo RENAME TO izigo_broken;
#      ALTER DATABASE izigo_restore RENAME TO izigo; → вернуть реплики → health-чек.
#    (обе ALTER — из сессии к dbname=postgres)
```

## Верификация после восстановления (обязательна)

```bash
psql "<строка подключения>" -tAc "
  select (select count(*) from information_schema.tables where table_schema='public'),
         (select count(*) from members), (select count(*) from payments),
         (select count(*) from ledger_entries),
         (select max(created_at) from payments);"
```

- Число таблиц соответствует проду (на 2026-07-03: **40**); счётчики members/payments — здравые.
- `max(created_at)` платежей ≈ ожидаемому моменту восстановления.
- Если приложение перевели на восстановленную БД: `GET /api/health` → 200 (проверяет БД
  + heartbeat планировщика), смоук в Mini App (баланс/список платежей).

## Restore-drill (повторять раз в квартал)

1. `~/projects/binar-mlm/ops/backup-izigo-pg.sh` — свежий дамп руками.
2. Восстановить в локальный докер-PG: `psql -h 127.0.0.1 -p 5544 -U postgres -c "CREATE DATABASE izigo_drill"`
   → `pg_restore -h 127.0.0.1 -p 5544 -U postgres -d izigo_drill --no-owner --no-privileges <дамп>`
   (пароль `postgres`; контейнер izigo-test-pg, см. CLAUDE.md).
3. Сверить счётчики с продом (запрос из «Верификации») — должны совпасть.
4. `DROP DATABASE izigo_drill`; дату и результат дописать строкой сюда:

| Дата | Дамп | Результат |
|---|---|---|
| 2026-07-03 | izigo-20260703-093152.dump | ✅ restore чистый, 40 таблиц, счётчики = прод |

## Мониторинг бэкапов

При любом провале (az/pg_dump/верификация) скрипт шлёт алёрт в **Telegram** — канал
server-watchdog (токен/чат из KV; email на mh-central мёртв by design — Azure блокирует 25-й
порт). Лог — `~/backups/izigo-pg/backup.log`. Ручная проверка свежести:
`ls -lt ~/backups/izigo-pg | head -3` — валидный `.dump` за сегодня должен быть;
болтающийся `.part` = оборванный прогон.
