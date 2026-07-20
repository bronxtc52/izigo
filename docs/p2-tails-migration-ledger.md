# P2-tails — migration ledger (порядок миграций блока)

Резервирование timestamp-префиксов, чтобы параллельные ветки не пересеклись:

| Задача | Миграция | Префикс |
|---|---|---|
| t2 | add_poll_tracking_to_payments_table (last_poll_result, last_polled_at, poll_error_streak) | `2026_07_20_100000` |
| t1 | — (миграций нет: BFF-proxy, бэк-контракт не меняется) | — |
| t3 | — (zero-footprint: только artisan-команда и отчёт) | — |
