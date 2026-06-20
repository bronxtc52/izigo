# План: Фаза 1 / цикл 1 — чистое доменное ядро (PV)

ТЗ: `docs/specs/2026-06-20-mlm-core-extraction.md`. Калькулятор-витрину не трогаем.

## Структура (новый чистый namespace `Modules\Calculator\Domain`)

```
Modules/Calculator/Domain/
  ValueObject/
    Money.php            # USD в центах (int), сложение/процент, без float
    Pv.php               # PV в сотых (int)
    Percent.php          # проценты (basis points)
  Model/
    MemberNode.php       # чистый узел: id, parentId, sponsorId, packageId, rankId,
                         #   leftLeg/rightLeg, pvPersonal/pvGroup, carryover-объёмы
    Network.php          # дерево: map id->node, обходы (placement вверх, sponsors вверх)
  Plan/
    PlanConfig.php       # проценты/глубины/пороги (binary%, referral[pkg][lvl],
                         #   leader[lvl][pkg][rank], maxRankDiff, депт) — из массива/конфига
    RankCondition.php    # пороги ранга (малая ветка PV, personalCount, inRank)
    Package.php          # id, sort, pv
    Rank.php             # id, sort, alias, bonus
  Repository/
    PackageRepository.php  # interface getById/getAll
    RankRepository.php     # interface getOrderedBySort
  Bonus/
    BinaryBonusCalculator.php    # пайринг min-ноги PV + carryover/flush, % вверх
    ReferralBonusCalculator.php  # % от PV пакета, глубина 2, по спонсорам
    LeaderBonusCalculator.php    # bonus-on-bonus, compression MAX_RANK_DIFF=2 (+ фикс null-deref)
    RankBonusCalculator.php      # разовая при повышении ранга
  Rank/
    RankQualifier.php            # конъюнкция условий, темпоральная отсечка maxNodeId
  CompensationEngine.php         # оркестратор: событие(узел) -> volumes -> ranks -> bonuses
  Dto/
    BonusLine.php, CalculationResult.php   # результат (тип бонуса, получатель, сумма, основание)
```

## Шаги
1. [ ] Прочитать текущие сервисы (BonusBinary/Leader/Rank/Referral, RankCheck, RankService,
       Node, NodeForCheckRanks, CalculatorService) — зафиксировать ТОЧНЫЕ формулы/пороги.
2. [ ] Value Objects (Money/Pv/Percent) + тесты на арифметику.
3. [ ] Plan/PlanConfig + Package/Rank/RankCondition + интерфейсы репозиториев.
4. [ ] MemberNode + Network (чистая модель дерева, обходы, накопление PV).
5. [ ] 4 калькулятора бонусов (база PV) + RankQualifier. Фикс null-deref в Leader.
6. [ ] CompensationEngine (оркестратор, детерминированный, без БД/побочек).
7. [ ] Golden unit-тесты (Tests/Unit/Domain): пайринг с carryover, реферальный по уровням,
       лидерский с compression, квалификация рангов (7/14/36 узлов), кейс цепочки до корня.
       Ожидаемые значения пересчитаны под PV (Bronze 90 / Silver 180 / Gold 540).
8. [ ] Прогон: pure-тесты зелёные без БД; калькулятор-витрина и LocalAuthTest не сломаны.

## Гейт 4
reviewer (корректность формул, чистота от Laravel, расхождения с ТЗ) → правки →
tester (прогон unit-suite + проверка, что витрина жива) → ручной обзор.

## Чек-лист
- [ ] VO  [ ] Plan/репозитории  [ ] Network  [ ] калькуляторы+квалификатор  [ ] движок  [ ] golden-тесты
