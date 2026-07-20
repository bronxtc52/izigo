# Бенчмарк движка пересчёта — 2026-07-20T16:54:41+00:00

БД: `izigo_bench` (pgsql), PHP 8.3.31, Laravel 12.63.0, seed 20260720, итераций 3, engines: v1,v2

## size=1000

| фаза | wall ms (mean) | min…max | SQL шт | SQL ms | peak MB |
|---|---:|---:|---:|---:|---:|
| v1_load | 9.9 | 9.9…10 | 1 | 1.1 | 45.8 |
| v1_engine | 245.3 | 244.2…245.9 | 0 | 0 | 45.8 |
| v1_activate | 3947.4 | 3846.3…4102.7 | 8722 | 2816.6 | 46.5 |
| v2_mark_paid | 3673.2 | 3582.6…3718.8 | 8381.7 | 2609.7 | 48.5 |
| v2_structure_bonus | 5.1 | 4.3…5.9 | 13 | 3.7 | 48.5 |

Топ SQL:

| SQL | кол-во | время ms |
|---|---:|---:|
| `insert into "member_bonus_lines" ("recipient_member_id", "type", "amount", "basis", "source_event_id", "calculated_at") values (?…) returning "id"` | 42246 | 13440.85 |
| `update "members" set "rank_id" = ?, "updated_at" = ? where "id" = ?` | 6000 | 1872.67 |
| `insert into "member_earnings" ("member_id", "total", "by_type", "updated_at") values (?…) returning "id"` | 1308 | 394.4 |
| `select * from "member_wallets" where "member_id" = ? limit {n} for update` | 466 | 130.69 |
| `insert into "ledger_entries" ("account_type", "amount_cents", "created_at", "direction", "idempotency_key", "member_id", "meta", "source_id", "source_type", "tx_id") values (?…)×N` | 248 | 88.46 |
| `select exists(select * from "ledger_entries" where "idempotency_key" = ?) as "exists"` | 248 | 73.42 |
| `insert into "member_wallets" ("member_id", "available_cents", "held_cents", "clawback_debt_cents", "currency", "updated_at") values (?…) on conflict do nothing` | 218 | 70.02 |
| `update "member_wallets" set "available_cents" = ? where "id" = ?` | 218 | 65.84 |
| `insert into "v2_pv_lots" ("owner_member_id", "side", "buyer_member_id", "origin_order_id", "origin_order_item_id", "pv_original", "pv_available", "pv_matched", "pv_reversed", "bv_usd_cents_original…` | 27 | 15.48 |
| `SELECT {n} AS held FROM pg_locks WHERE locktype = ? AND pid = pg_backend_pid() AND granted AND classid = ? AND objid = ?` | 30 | 11.45 |

## size=5000

| фаза | wall ms (mean) | min…max | SQL шт | SQL ms | peak MB |
|---|---:|---:|---:|---:|---:|
| v1_load | 61.5 | 54.4…73.5 | 1 | 5.1 | 69.2 |
| v1_engine | 6395.5 | 6275.2…6460.3 | 0 | 0 | 69.2 |
| v1_activate | 27651.2 | 26776.9…29037.3 | 52739 | 16247.1 | 70.5 |
| v2_mark_paid | 27230.1 | 27001.1…27651.5 | 50771 | 15638.6 | 70.5 |
| v2_structure_bonus | 6.8 | 6.4…7.1 | 16 | 5.3 | 70.5 |

Топ SQL:

| SQL | кол-во | время ms |
|---|---:|---:|
| `insert into "member_bonus_lines" ("recipient_member_id", "type", "amount", "basis", "source_event_id", "calculated_at") values (?…) returning "id"` | 267558 | 82361.8 |
| `update "members" set "rank_id" = ?, "updated_at" = ? where "id" = ?` | 30000 | 9286.86 |
| `insert into "member_earnings" ("member_id", "total", "by_type", "updated_at") values (?…) returning "id"` | 6228 | 1769.87 |
| `select * from "member_wallets" where "member_id" = ? limit {n} for update` | 2116 | 617.7 |
| `insert into "ledger_entries" ("account_type", "amount_cents", "created_at", "direction", "idempotency_key", "member_id", "meta", "source_id", "source_type", "tx_id") values (?…)×N` | 1078 | 390.7 |
| `insert into "member_wallets" ("member_id", "available_cents", "held_cents", "clawback_debt_cents", "currency", "updated_at") values (?…) on conflict do nothing` | 1038 | 352.18 |
| `update "member_wallets" set "available_cents" = ? where "id" = ?` | 1037 | 327.77 |
| `select exists(select * from "ledger_entries" where "idempotency_key" = ?) as "exists"` | 1078 | 327.3 |
| `delete from "member_bonus_lines"` | 6 | 49.07 |
| `select "id", "name", "sponsor_id", "parent_id", "package_id" from "members" order by "id" asc` | 9 | 42.9 |

## size=20000

| фаза | wall ms (mean) | min…max | SQL шт | SQL ms | peak MB |
|---|---:|---:|---:|---:|---:|
| v1_load | 228.7 | 226.3…230.3 | 1 | 18.7 | 154.5 |
| v1_engine | 108001.3 | 99025.4…115646.8 | 0 | 0 | 159.8 |
| v1_activate | 204975.9 | 198053.2…211236.2 | 247519.7 | 74481.9 | 163.8 |
| v2_mark_paid | 206503.1 | 204495.8…208093.4 | 240188.7 | 73414.2 | 164.5 |
| v2_structure_bonus | 7.6 | 6.4…9.2 | 18 | 5.7 | 158.5 |

Топ SQL:

| SQL | кол-во | время ms |
|---|---:|---:|
| `insert into "member_bonus_lines" ("recipient_member_id", "type", "amount", "basis", "source_event_id", "calculated_at") values (?…) returning "id"` | 1297800 | 393926.66 |
| `update "members" set "rank_id" = ?, "updated_at" = ? where "id" = ?` | 120000 | 35904.93 |
| `insert into "member_earnings" ("member_id", "total", "by_type", "updated_at") values (?…) returning "id"` | 22380 | 6093.51 |
| `select * from "member_wallets" where "member_id" = ? limit {n} for update` | 7495 | 2014.01 |
| `insert into "ledger_entries" ("account_type", "amount_cents", "created_at", "direction", "idempotency_key", "member_id", "meta", "source_id", "source_type", "tx_id") values (?…)×N` | 3765 | 1265.67 |
| `insert into "member_wallets" ("member_id", "available_cents", "held_cents", "clawback_debt_cents", "currency", "updated_at") values (?…) on conflict do nothing` | 3730 | 1171.37 |
| `update "member_wallets" set "available_cents" = ? where "id" = ?` | 3726 | 1086.21 |
| `select exists(select * from "ledger_entries" where "idempotency_key" = ?) as "exists"` | 3765 | 1056.62 |
| `delete from "member_bonus_lines"` | 6 | 873.28 |
| `select "id", "name", "sponsor_id", "parent_id", "package_id" from "members" order by "id" asc` | 9 | 169 |
