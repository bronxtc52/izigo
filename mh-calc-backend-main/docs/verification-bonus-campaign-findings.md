# Прогон системы расчёта бонусов — находки и бэклог

Дата: 2026-06-23. Метод: независимый оракул (`tests/Verification/BonusOracle.php`),
реализованный с нуля по спеке маркетинг-плана, + дифференциальное сравнение
«живой движок vs оракул vs ручной счёт» на инкрементальных деревьях и adversarial-кейсах.
Среда — локальная (Postgres, `izigo_*`), прод не затронут. Движок `Modules/Calculator`
(запретная зона) только читался.

## Вердикт

Ядро расчёта **4 бонусов — корректно**:

| Бонус | Статус | Покрытие |
|---|---|---|
| Реферальный | ✅ верно | глубина-2 cutoff, % по пакету получателя, L2=0 у Bronze, база = PV покупателя, округление |
| Бинарный | ✅ верно | carryover 4+ активаций, обе ноги обязательны, rank-0 откладывает выплату до ранга 1, глубокая цепочка, no-flush на нуле |
| Ранговый | ✅ верно | разовая выплата, не дублируется при пересчёте, все гейты (small-branch обе ноги / personal_count / in_rank_count r4), идемпотентность |
| Лидерский | ✅ суммы верны / ⚠️ см. находку №1 | L1/L2 bonus-on-bonus, идемпотентность; вопрос — граница ранг-компрессии |

Дифф-прогон: **160 шагов × 8 сидов, 0 расхождений**. Adversarial-кейсы по каждому бонусу — все PASS.
API админки = расчётной истине; доступ deny-by-default (нет токена → 401, чужая роль → 403).

By-design (не баг): участник на `rank 0` (никого не пригласил лично) не зарабатывает бинар —
`binaryPercent` задан только для рангов 1–4; объём копится и выплачивается по достижении ранга 1.

## Находки

### №1 — Лидерская ранг-компрессия: скан захватывает самого инициатора A. РАЗОБРАНО → НЕ БАГ.
`Modules/Calculator/Domain/Bonus/LeaderBonusCalculator.php::hasHigherRankInChain` (стр. ~61)
начинает скан с `$binaryReceiver` (A) включительно. Эффект: если A обогнал прямого спонсора S
по рангу на ≥2 — S теряет лидерский с бинара A (доказано `LeaderAndCompressionTest`, gap 1215c).

**Вердикт: поведение движка верное и намеренное.** Подтверждено:
- легаси-симулятор `Services/Bonus/BonusLeaderService.php::hasMoreRankInChain` (стр. 75-91) —
  идентичный A-inclusive алгоритм (стартует с `$binaryBonusReceiver`, проверяет A на первой итерации);
- легаси-ТЗ: «разница в статусах между Спонсором и **Партнёром** … минус два и более» (Партнёр = A);
- конвенция комп-планов: differential/blocking — даунлайн, обогнавший аплайна, блокирует ему override;
- golden-тест `BonusCalculatorsTest::testLeaderRankCompressionSkipsSponsor` (стр. 117-136) фиксирует
  именно A-inclusive (s1 сжат только потому, что A его обогнал, без промежуточного узла);
- зрелый «Лидер»/mhg локально недоступен (движок исключён из сравнения) — контр-доказательств нет.

Единственный артефакт — **неточный докстринг** (стр. 54-56, «между $receiver и получателем»,
скопирован из рыхлого легаси-комментария). Рекомендация: поправить ТОЛЬКО докстринг (комментарий,
без изменения поведения), привести к A-inclusive формулировке. Код не трогать.

### №2 — MemberCard / MembersList: «Пакет» = сырой `package_id`. СРЕДНЯЯ. (✅ исправлено)
`mh-calc-frontend-main/src/views/admin/MemberCard.js:144`,
`mh-calc-frontend-main/src/views/admin/MembersList.js:59` — показывают число «1» вместо «Bronze».
Причина: `AdminService::rowOf` (`AdminService.php:135-144`) отдаёт сырой id, тогда как
`AdminReportService::reportUsers` (`:175-191`) имена резолвит. Фикс: добавить резолв `package`
в `rowOf` и рендерить имя.

### №3 — Отчёт «расход на бонусы»: снимок по типам ≠ итог за период. СРЕДНЯЯ (UX-ловушка). (✅ исправлено)
`mh-calc-frontend-main/src/views/admin/web/Reports.js:174-212`. Таблица по типам — текущий
снимок (без периода), карточка-итог — фильтр по датам; при выбранном диапазоне строки не
суммируются в итог. Фикс: прятать таблицу при активном диапазоне либо переименовать
«Структура расхода (текущий снимок, без периода)».

### №4 — Дерево генеалогии не показывает пакет/бонусы/PV/ранг по узлу. НИЗКАЯ-СРЕДНЯЯ. (✅ пакет добавлен)
`mh-calc-frontend-main/src/views/admin/web/Genealogy.js:17-32` — только имя/id/статус/L-R.
`package_id` уже приходит в API, но отбрасывается. Дёшево: добавить тег пакета в `toTreeData`.

### №5 — PHP 8.5 deprecation `PDO::MYSQL_ATTR_SSL_CA`. НИЗКАЯ. (✅ исправлено)
`config/database.php:61-63`. Заменено на `\Pdo\Mysql::ATTR_SSL_CA`; константа трогается
только когда `MYSQL_ATTR_SSL_CA` реально задан. Шум deprecation в тестах ушёл (проверено).

LLM→HTML в админке: риска нет — markdown/LLM-вывод в проверенных экранах не рендерится.

## Регресс-актив
`mh-calc-backend-main/tests/Verification/` — оракул + self-tests + дифф-харнесс + 4 сценарных
набора.

- `BonusOracleTest` — чистый, без БД: `php artisan test tests/Verification/BonusOracleTest.php`.
- `DifferentialHarnessTest` — на стандартной `izigo_test`: `php artisan test tests/Verification/DifferentialHarnessTest.php`.
- Сценарные наборы (`LeaderAndCompressionTest`, `RankBonusGatesTest`, `BinaryReferralAdversarialTest`,
  `AdminUiDisplayTest`) — каждый на отдельной БД, имя зашито в `setUp`. Временные БД `izigo_v_*`
  после прогона удалены (scratch). Чтобы перезапустить — пересоздать БД и мигрировать:

```bash
for db in izigo_v_leader izigo_v_rank izigo_v_binref izigo_v_ui; do
  createdb "$db" && psql "$db" -c 'CREATE EXTENSION IF NOT EXISTS ltree;'
done
DB_DATABASE=izigo_v_leader php artisan migrate:fresh --force
DB_DATABASE=izigo_v_leader php artisan test tests/Verification/LeaderAndCompressionTest.php
# аналогично для izigo_v_rank / izigo_v_binref / izigo_v_ui
```
