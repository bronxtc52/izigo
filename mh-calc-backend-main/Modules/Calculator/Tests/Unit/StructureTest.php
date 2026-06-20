<?php

namespace Modules\Calculator\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Dto\NodeCreateData;
use Modules\Calculator\Dto\Resource\Bonus\BonusDataReferral;
use Modules\Calculator\Dto\UserNodeData;
use Modules\Calculator\Models\CalculatorUserToken;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Rank;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Services\Bonus\BonusBinaryService;
use Modules\Calculator\Services\Bonus\BonusReferralService;
use Modules\Calculator\Services\CalculatorService;
use Modules\Calculator\Services\StructureService;
use Tests\TestCase;

class StructureTest extends TestCase
{
    use RefreshDatabase;

    private $data = [
        'username' => 'First user name',
        'package_id' => 1
    ];
    private ?CalculatorUserToken $calculatorUser = null;

    public function __construct(string $name)
    {
        parent::__construct($name);
    }

    /**
     * Создание структуры
     */
    public function testCreate(): void
    {
        /** @var Structure $structure */
        $structure = $this->createStructure($username = 'First user name 1', $packageId = 1);

        $this->assertTrue(!empty($structure->data));
        $this->assertTrue(!empty($structure->getRoot()));

        $structure = Structure::find($structure->id);

        if (!empty($structure->getRoot())) {
            $this->assertTrue($structure->getRoot()->sponsor_id == null);
            $this->assertTrue($structure->getRoot()->package_id == $packageId);
            $this->assertTrue($structure->getRoot()->name == $username);
            $this->assertTrue($structure->getRoot()->childCount() == 0);
        }

        $this->assertDatabaseHas('calculator_structures', ['id' => $structure->id]);
    }

    /**
     * Создание структуры
     */
    public function testAddNode(): void
    {
        /** @var Structure $structure */
        $structure = $this->createStructure('First user name 1', 1);
        if (empty($structure->getRoot())) {
            return;
        }

        $structure = $this->addNode($structure, $structure->getRoot()->id, $position = 2, 0,
            $username = 'Second user name 2', $packageId = 2);

        $this->assertTrue($structure->getRoot()->childCount() == 1);
        $this->assertTrue(!empty($structure->getRoot()->children[1]));

        if ($structure->getRoot()->children[1] ?? null) {
            $this->assertTrue($structure->getRoot()->children[1]->sponsor_id == $structure->getRoot()->id);
            $this->assertTrue($structure->getRoot()->children[1]->package_id == $packageId);
            $this->assertTrue($structure->getRoot()->children[1]->name == $username);
            $this->assertTrue($structure->getRoot()->children[1]->name == $username);
            $this->assertTrue($structure->getRoot()->children[1]->pos == $position);
        }
    }

    public function testMarketing1(): void
    {
        $locale = 'ru';

        $structure = $this->createStructureWithNodes(1, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 2],//id 2
        ]);

        //передавать сюда именно config('app.currency_code') из Accept-Currency
        $calculator = new CalculatorService($locale, $structure);
        $calculator->calculate();

        $user1 = $structure->getRoot();
        $user2 = $structure->getNodeById($user1, 2);

        $packageMap = Package::getMap($locale);

        $this->assertTrue($user1->rank_id == 1);
        $this->assertTrue($user2->rank_id == 0);
        //$this->assertTrue($user1->bonus_rank_sum == $rankMap[2]->bonus->rank_bonus_amount);

        $this->assertTrue($user1->bonus_binary_sum == 0);
        $this->assertTrue($user1->bonus_leader_sum == 0);

        $bonusMap = [];
        /** @var BonusDataReferral $bonus */
        foreach ($user1->bonus_referral_list as $bonus) {
            $bonusMap[$bonus->initiator_id] = $bonus->amount;
        }
        $bonusReferralService = new BonusReferralService();
        //1 уровень в структуре по ЛП
        $percent = $bonusReferralService->getPercent($locale, 1, $user1);
        $this->assertTrue(isset($bonusMap[2]) && $bonusMap[2] == $packageMap[$user2->package_id]->volume->bv / 100 * $percent);

    }

    public function testMarketing2(): void
    {
        $locale = 'ru';

        $structure = $this->createStructureWithNodes(2, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
            ['sponsor_id' => 1, 'top' => 2, 'position' => 2, 'package' => 2],//id 4
            ['sponsor_id' => 3, 'top' => 3, 'position' => 1, 'package' => 2],//id 5
            ['sponsor_id' => 1, 'top' => 4, 'position' => 1, 'package' => 3],//id 6
            ['sponsor_id' => 5, 'top' => 5, 'position' => 1, 'package' => 3],//id 7
        ]);

        //передавать сюда именно config('app.currency_code') из Accept-Currency
        $calculator = new CalculatorService($locale, $structure);
        $calculator->calculate();

        $user1 = $structure->getRoot();
        $user2 = $structure->getNodeById($user1, 2);
        $user3 = $structure->getNodeById($user1, 3);
        $user4 = $structure->getNodeById($user1, 4);
        $user5 = $structure->getNodeById($user1, 5);
        $user6 = $structure->getNodeById($user1, 6);
        $user7 = $structure->getNodeById($user1, 7);

        $packageMap = Package::getMap($locale);
        $rankMap = Rank::getMap($locale);

        $this->assertTrue($user1->rank_id == 2);
        $this->assertTrue($user1->bonus_rank_sum == $rankMap[2]->bonus->rank_bonus_amount);

        //binary bonus check
        $binaryBonusService = new BonusBinaryService();
        $leftVolume = $packageMap[$user2->package_id]->volume->bv;
        $rightVolume = $packageMap[$user3->package_id]->volume->bv;
        $minBinary = min($leftVolume, $rightVolume);
        $firstBinary = $minBinary / 100 * $binaryBonusService->getPercent(1);

        $leftVolume += -$minBinary + $packageMap[$user4->package_id]->volume->bv;
        $rightVolume += -$minBinary + $packageMap[$user5->package_id]->volume->bv;
        $minBinary = min($leftVolume, $rightVolume);
        $secondBinary = $minBinary / 100 * $binaryBonusService->getPercent(1);

        $leftVolume += -$minBinary + $packageMap[$user6->package_id]->volume->bv;
        $rightVolume += -$minBinary + $packageMap[$user7->package_id]->volume->bv;
        $minBinary = min($leftVolume, $rightVolume);
        $thirdBinary = $minBinary / 100 * $binaryBonusService->getPercent(2);

        $this->assertTrue($user1->bonus_binary_sum == $firstBinary + $secondBinary + $thirdBinary);
        //end binary bonus check

        //referral bonus check
        $bonusReferralService = new BonusReferralService();

        $bonusMap = [];
        /** @var BonusDataReferral $bonus */
        foreach ($user1->bonus_referral_list as $bonus) {
            $bonusMap[$bonus->initiator_id] = $bonus->amount;
        }

        //1 уровень в структуре по ЛП
        $percent = $bonusReferralService->getPercent($locale, 1, $user1);
        $this->assertTrue(isset($bonusMap[3]) && $bonusMap[3] == $packageMap[$user3->package_id]->volume->bv / 100 * $percent);
        $this->assertTrue(isset($bonusMap[4]) && $bonusMap[4] == $packageMap[$user4->package_id]->volume->bv / 100 * $percent);

        //2 уровень в структуре по ЛП
        $percent = $bonusReferralService->getPercent($locale, 2, $user1);
        $this->assertTrue(isset($bonusMap[5]) && $bonusMap[5] == $packageMap[$user5->package_id]->volume->bv / 100 * $percent);
        //end referral bonus check

        //leader bonus check
        $bonusMap = [];
        /** @var BonusDataReferral $bonus */
        foreach ($user1->bonus_leader_list as $bonus) {
            $bonusMap[$bonus->initiator_id] = $bonus->amount;
        }

        $this->assertTrue(isset($bonusMap[7]));

        //rank check
        $this->assertTrue($user2->rank_id == 0);
        $this->assertTrue($user3->rank_id == 1);
        $this->assertTrue($user3->bonus_rank_sum == 0);
        $this->assertTrue($user4->rank_id == 0);
        $this->assertTrue($user5->rank_id == 1);
        $this->assertTrue($user6->rank_id == 0);
        $this->assertTrue($user7->rank_id == 0);
    }

    public function testRank2(): void
    {
        $locale = 'ru';

        $structure = $this->createStructureWithNodes(2, $this->getNodesForRank2());

        //передавать сюда именно config('app.currency_code') из Accept-Currency
        $calculator = new CalculatorService($locale, $structure);
        $calculator->calculate();

        $user1 = $structure->getRoot();
        $rankMap = Rank::getMap($locale);

        $this->assertTrue($user1->rank_id == 2);
        $this->assertFalse(isset($user1->bonus_rank_list[$rankMap[1]->id]));
        $this->assertTrue(isset($user1->bonus_rank_list[$rankMap[2]->id]));
        $this->assertTrue($user1->bonus_rank_sum == $rankMap[2]->bonus->rank_bonus_amount);
    }

    public function testRank3(): void
    {
        $locale = 'ru';

        $structure = $this->createStructureWithNodes(2, $this->getNodesForRank3());

        //передавать сюда именно config('app.currency_code') из Accept-Currency
        $calculator = new CalculatorService($locale, $structure);
        $calculator->calculate();

        $user1 = $structure->getRoot();
        $rankMap = Rank::getMap($locale);

        $checkSum = 0;

        $this->assertTrue($user1->rank_id == 3);
        $this->assertFalse(isset($user1->bonus_rank_list[$rankMap[1]->id]));

        foreach ([2, 3] as $rankId) {
            $this->assertTrue(isset($user1->bonus_rank_list[$rankMap[$rankId]->id])
                && $user1->bonus_rank_list[$rankMap[$rankId]->id]->amount == $rankMap[$rankId]->bonus->rank_bonus_amount);
            $checkSum += $rankMap[$rankId]->bonus->rank_bonus_amount;
        }
        $this->assertTrue($user1->bonus_rank_sum == $checkSum);
    }

    public function testRank4(): void
    {
        $locale = 'ru';

        $structure = $this->createStructureWithNodes(2, $this->getNodesForRank4());

        //передавать сюда именно config('app.currency_code') из Accept-Currency
        $calculator = new CalculatorService($locale, $structure);
        $calculator->calculate();

        $user1 = $structure->getRoot();
        $rankMap = Rank::getMap($locale);

        $checkSum = 0;

        foreach ([2, 6, 10] as $userId) {
            $user = $structure->getNodeById($user1, $userId);
            $this->assertTrue($user->rank_id == 2);
        }

        $this->assertFalse(isset($user1->bonus_rank_list[$rankMap[1]->id]));
        $this->assertTrue($user1->rank_id == 4);

        foreach ([2, 3, 4] as $rankId) {
            $this->assertTrue(isset($user1->bonus_rank_list[$rankMap[$rankId]->id])
                && $user1->bonus_rank_list[$rankMap[$rankId]->id]->amount == $rankMap[$rankId]->bonus->rank_bonus_amount);
            $checkSum += $rankMap[$rankId]->bonus->rank_bonus_amount;
        }

        $this->assertTrue($user1->bonus_rank_sum == $checkSum);

        //dump($structure->getNodeById($user1, 2)->all_events);
        //dd($user1->all_events);
    }

    private function getNodesForRank2(): array
    {
        return [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
            ['sponsor_id' => 1, 'top' => 2, 'position' => 2, 'package' => 2],//id 4
            ['sponsor_id' => 3, 'top' => 3, 'position' => 1, 'package' => 3],//id 5
            ['sponsor_id' => 1, 'top' => 4, 'position' => 1, 'package' => 3],//id 6
            ['sponsor_id' => 5, 'top' => 5, 'position' => 1, 'package' => 2],//id 7
        ];
    }

    private function getNodesForRank3(): array
    {
        return array_merge($this->getNodesForRank2(), [
            ['sponsor_id' => 1, 'top' => 2, 'position' => 1, 'package' => 3],//id 8
            ['sponsor_id' => 1, 'top' => 4, 'position' => 2, 'package' => 3],//id 9
            ['sponsor_id' => 1, 'top' => 5, 'position' => 2, 'package' => 3],//id 10
            ['sponsor_id' => 1, 'top' => 3, 'position' => 2, 'package' => 3],//id 11
            ['sponsor_id' => 1, 'top' => 8, 'position' => 1, 'package' => 3],//id 12
            ['sponsor_id' => 1, 'top' => 7, 'position' => 1, 'package' => 3],//id 13
            ['sponsor_id' => 1, 'top' => 7, 'position' => 2, 'package' => 3],//id 14
        ]);
    }

    private function getNodesForRank4(): array
    {
        return array_merge($this->getNodesForRank3(), [
            ['sponsor_id' => 1, 'top' => 11, 'position' => 1, 'package' => 3],//id 15
            ['sponsor_id' => 1, 'top' => 11, 'position' => 2, 'package' => 3],//id 16
            ['sponsor_id' => 10, 'top' => 10, 'position' => 2, 'package' => 3],//id 17
            ['sponsor_id' => 1, 'top' => 13, 'position' => 1, 'package' => 3],//id 18
            ['sponsor_id' => 1, 'top' => 13, 'position' => 2, 'package' => 3],//id 19
            ['sponsor_id' => 1, 'top' => 14, 'position' => 1, 'package' => 3],//id 20
            ['sponsor_id' => 1, 'top' => 14, 'position' => 2, 'package' => 3],//id 21
            ['sponsor_id' => 2, 'top' => 12, 'position' => 1, 'package' => 3],//id 22
            ['sponsor_id' => 2, 'top' => 12, 'position' => 2, 'package' => 3],//id 23
            ['sponsor_id' => 2, 'top' => 6, 'position' => 1, 'package' => 3],//id 24
            ['sponsor_id' => 2, 'top' => 6, 'position' => 2, 'package' => 3],//id 25
            ['sponsor_id' => 1, 'top' => 22, 'position' => 1, 'package' => 3],//id 26
            ['sponsor_id' => 1, 'top' => 22, 'position' => 2, 'package' => 3],//id 27
            ['sponsor_id' => 1, 'top' => 24, 'position' => 1, 'package' => 3],//id 28
            ['sponsor_id' => 1, 'top' => 24, 'position' => 2, 'package' => 3],//id 29
            ['sponsor_id' => 10, 'top' => 10, 'position' => 1, 'package' => 3],//id 30
            ['sponsor_id' => 10, 'top' => 17, 'position' => 2, 'package' => 3],//id 31
            ['sponsor_id' => 10, 'top' => 30, 'position' => 1, 'package' => 3],//id 32
            ['sponsor_id' => 6, 'top' => 25, 'position' => 1, 'package' => 3],//id 33
            ['sponsor_id' => 6, 'top' => 25, 'position' => 2, 'package' => 3],//id 34
            ['sponsor_id' => 6, 'top' => 33, 'position' => 1, 'package' => 3],//id 35
            ['sponsor_id' => 6, 'top' => 33, 'position' => 2, 'package' => 3],//id 36
        ]);
    }

    private function createStructureWithNodes(int $rootPackage, array $dataList): ?Structure
    {
        /** @var Structure $structure */
        $structure = $this->createStructure(null, $rootPackage);
        if (empty($structure->getRoot())) {
            return null;
        }

        foreach ($dataList as $data) {
            $structure = $this->addNode($structure, $data['top'], $data['position'], $data['sponsor_id'], null, $data['package']);
        }

        return $structure;
    }

    private function addNode(Structure $structure, int $topNodeId, int $position, int $sponsorId,
                             ?string   $username = null, ?int $packageId = null): ?Structure
    {
        /** @var StructureService $service */
        $service = resolve(StructureService::class);

        return $service->addNode(NodeCreateData::from([
            'structure' => $structure,
            'top_node_id' => $topNodeId,
            'position' => $position,
            'sponsor_id' => $sponsorId,
            'username' => $username,
            'package_id' => $packageId
        ]));
    }

    private function createStructure(?string $username = null, ?int $packageId = null): ?Structure
    {
        $this->calculatorUser = CalculatorUserToken::first();

        /** @var StructureService $service */
        $service = resolve(StructureService::class);

        return $service->create(UserNodeData::from([
            'username' => $username,
            'sponsor_id' => null,
            'package_id' => $packageId
        ]), $this->calculatorUser->user->id);
    }
}
