<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Dto\UserNodeData;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\CalculatorUserToken;
use Tests\TestCase;

class StructureTest extends TestCase
{
    use RefreshDatabase;

    private ?CalculatorUserToken $superToken = null;

    /**
     * Легаси-флоу витрины требует существующий валидный CalculatorUserToken
     * (его читает SetCalculatorUserMiddleware по заголовку CalculatorAuthToken).
     * В тест-БД его никто не сеет — создаём здесь. Раньше тест искал токен по
     * колонке email, которой больше нет (перенесена в calculator_users), поэтому
     * падал с «column "email" does not exist».
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = CalculatorUser::query()->create(['email' => 'super@izigo.test']);
        $this->superToken = CalculatorUserToken::query()->create([
            'calculator_user_id' => $user->id,
            'token' => 'feature-calculator-token',
            'expires_at' => Carbon::now()->addMonth(),
        ]);
    }

    /**
     * Создание структуры
     */
    public function testCreate(): void
    {
        $response = $this->structureCreate($username = 'First user name 1', $packageId = 1);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'token_view',
                    'token_edit',
                ],
            ])
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.root.name', $username)
            ->assertJsonPath('data.root.package_id', $packageId);
    }

    private function getAuthHeaders():array
    {
        $this->superToken->update(['expires_at' => Carbon::now()->addMonth()]);

        return [
            'CalculatorAuthToken' => $this->superToken->token
        ];
    }

    private function structureCreate(string $username, int $packageId): TestResponse
    {
        return $this->postJson(route('calculator.structure.create'), [
            'username' => $username,
            'package_id' => $packageId
        ], $this->getAuthHeaders());
    }

    /**
     * Добавление ноды
     */
    public function testAddNode(): void
    {
        $response = $this->structureCreate('First user name 1', 1);
        $content = json_decode($response->getContent());

        if (empty($content->data->token_view) || empty($content->data->root->id)) {
            return;
        }

        $response = $this->createNode($content->data->token_edit, $content->data->root->id,
            $pos = 2, $username = 'name2', $packageId = 2, $this->getAuthHeaders());

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token_view',
                    'token_edit',
                ],
            ])
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.root.children.1.name', $username)
            ->assertJsonPath('data.root.children.1.parent_id', $content->data->root->id)
            ->assertJsonPath('data.root.children.1.sponsor_id', $content->data->root->id)
            ->assertJsonPath('data.root.children.1.package_id', $packageId)
            ->assertJsonPath('data.root.children.1.pos', $pos);
    }

    /**
     * Попытка добавление ноды пользователем,
     * у которого есть ссылка только на просмотр
     */
    public function testAddNodeForbidden(): void
    {
        $response = $this->structureCreate('First user name 1', 1);
        $content = json_decode($response->getContent());

        if (empty($content->data->token_view) || empty($content->data->root->id)) {
            return;
        }

        $this->superToken->update(['expires_at' => Carbon::now()->subHour()]);

        CalculatorAuth::setToken(null);
        $response = $this->createNode($content->data->token_view, $content->data->root->id,
            1, 'name2', 2, ['CalculatorAuthToken' => 'not valid token']);

        $response->assertForbidden();
    }

    /**
     * Создаст структуру с нодами,
     * вернет данные созданной структуры
     *
     * @param int $rootPackage
     * @param array $dataList
     * @return int|null
     */
    private function createStructureWithNodes(int $rootPackage, array $dataList)
    {
        $response = $this->structureCreate('First user name 1', 1);
        $content = json_decode($response->getContent());
        if (empty($content->data->token_edit) || empty($content->data->root->id)) {
            return null;
        }

        $nodeContent = null;
        foreach ($dataList as $data) {
            $nodeResponse = $this->createNode($content->data->token_edit, $data['top'], $data['position'], null, $data['package'], $this->getAuthHeaders());
            $nodeContent = json_decode($nodeResponse->getContent());

            if (empty($nodeContent->data->token_edit) || empty($nodeContent->data->root->id)) {
                return null;
            }
        }
        return $nodeContent;
    }

    /**
     * Редактирование ноды
     */
    public function testNodeUpdate(): void
    {
        $structure = $this->createStructureWithNodes(2, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
            ['sponsor_id' => 1, 'top' => 2, 'position' => 2, 'package' => 2],//id 4
            ['sponsor_id' => 3, 'top' => 3, 'position' => 1, 'package' => 2],//id 5
            ['sponsor_id' => 1, 'top' => 4, 'position' => 1, 'package' => 3],//id 6
            ['sponsor_id' => 5, 'top' => 5, 'position' => 1, 'package' => 2],//id 7
        ]);

        $node = $structure->data->root->children[1]->children[0]->children[0] ?? null;

        if (!empty($node)) {
            $availableSponsorsMap = (array)$node->possible_sponsor_list;
            unset($availableSponsorsMap[$node->sponsor_id]);
            $availableSponsorsList = array_keys($availableSponsorsMap);

            $newSponsorId = $availableSponsorsList[mt_rand(0, count($availableSponsorsList) - 1)];
            $newUsername = " newNode {$node->id}";
            $newPackageId = 3;

            $updateResponse = $this->postJson(route('calculator.structure.node.update', [
                'node_id' => $node->id,
                '_method' => 'PUT'
            ]), [
                'structure_token' => $structure->data->token_edit,
                'username' => $newUsername,
                'sponsor_id' => $newSponsorId,
                'package_id' => $newPackageId
            ], $this->getAuthHeaders());

            $updateResponse
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'token_view',
                        'token_edit',
                    ],
                ])
                ->assertJsonPath('data.can_edit', true)
                ->assertJsonPath('data.root.children.1.children.0.children.0.name', trim($newUsername))
                ->assertJsonPath('data.root.children.1.children.0.children.0.package_id', $newPackageId)
                ->assertJsonPath('data.root.children.1.children.0.children.0.sponsor_id', $newSponsorId)
                ->assertJsonPath('data.root.children.1.children.0.children.0.parent_id', $node->parent_id)
                ->assertJsonPath('data.root.children.1.children.0.children.0.pos', $node->pos);
        }
    }

    /**
     * Редактирование корневой ноды
     */
    public function testRootNodeUpdate(): void
    {
        $structure = $this->createStructureWithNodes(2, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
            ['sponsor_id' => 1, 'top' => 2, 'position' => 2, 'package' => 2],//id 4
        ]);

        $root = $structure->data->root ?? null;

        if (!empty($root)) {
            $newUsername = "NameOfRootNode";
            $newPackageId = 3;

            $updateResponse = $this->postJson(route('calculator.structure.node.update', [
                'node_id' => $root->id,
                '_method' => 'PUT'
            ]), [
                'structure_token' => $structure->data->token_edit,
                'username' => $newUsername,
                'sponsor_id' => 0,
                'package_id' => $newPackageId
            ], $this->getAuthHeaders());

            $updateResponse
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'token_view',
                        'token_edit',
                    ],
                ])
                ->assertJsonPath('data.can_edit', true)
                ->assertJsonPath('data.root.name', $newUsername)
                ->assertJsonPath('data.root.package_id', $newPackageId)
                ->assertJsonPath('data.root.sponsor_id', 0)
                ->assertJsonPath('data.root.parent_id', 0)
                ->assertJsonPath('data.root.pos', 0);
        }
    }

    /**
     * Удаление ноды со всей веткой (можно только так)
     */
    public function testNodeDelete(): void
    {
        $structure = $this->createStructureWithNodes(2, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
            ['sponsor_id' => 1, 'top' => 2, 'position' => 2, 'package' => 2],//id 4
            ['sponsor_id' => 3, 'top' => 3, 'position' => 1, 'package' => 2],//id 5
            ['sponsor_id' => 1, 'top' => 4, 'position' => 1, 'package' => 3],//id 6
            ['sponsor_id' => 5, 'top' => 5, 'position' => 1, 'package' => 2],//id 7
        ]);

        $node = $structure->data->root->children[1]->children[0] ?? null;

        if (!empty($node)) {
            $deleteResponse = $this->deleteJson(route('calculator.structure.node.delete', [
                'node_id' => $node->id
            ]), [
                'structure_token' => $structure->data->token_edit,
            ]);

            $deleteResponse
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'token_view',
                        'token_edit',
                    ],
                ])
                ->assertJsonPath('data.can_edit', true)
                ->assertJsonPath('data.root.children.1.children.0', null)
                ->assertJsonPath('data.root.children.1.children.1', null);
        }
    }

    /**
     * Нет доступа к удалению ноды
     */
    public function testNodeDeleteForbidden(): void
    {
        $structure = $this->createStructureWithNodes(2, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
        ]);

        $node = $structure->data->root->children[1] ?? null;

        if (!empty($node)) {
            CalculatorAuth::setToken(null);
            $deleteResponse = $this->deleteJson(route('calculator.structure.node.delete', [
                'node_id' => $node->id
            ]), [
                'structure_token' => $structure->data->token_view,
            ]);

            $deleteResponse->assertForbidden();
        }
    }

    /**
     * Очистка структуры
     */
    public function testStructureClear(): void
    {
        $structure = $this->createStructureWithNodes(2, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
            ['sponsor_id' => 1, 'top' => 2, 'position' => 2, 'package' => 2],//id 4
            ['sponsor_id' => 3, 'top' => 3, 'position' => 1, 'package' => 2],//id 5
            ['sponsor_id' => 1, 'top' => 4, 'position' => 1, 'package' => 3],//id 6
            ['sponsor_id' => 5, 'top' => 5, 'position' => 1, 'package' => 2],//id 7
        ]);

        $clearResponse = $this->deleteJson(route('calculator.structure.clear', [
            'structure' => $structure->data->token_edit,
        ]));

        $clearResponse
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token_view',
                    'token_edit',
                ],
            ])
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.root.children.0', null)
            ->assertJsonPath('data.root.children.1', null);
    }

    /**
     * Нет доступа к очистке структуры
     */
    public function testStructureClearForbidden(): void
    {
        $structure = $this->createStructureWithNodes(2, [
            ['sponsor_id' => 1, 'top' => 1, 'position' => 1, 'package' => 3],//id 2
            ['sponsor_id' => 1, 'top' => 1, 'position' => 2, 'package' => 2],//id 3
            ['sponsor_id' => 1, 'top' => 2, 'position' => 2, 'package' => 2],//id 4
            ['sponsor_id' => 3, 'top' => 3, 'position' => 1, 'package' => 2],//id 5
            ['sponsor_id' => 1, 'top' => 4, 'position' => 1, 'package' => 3],//id 6
            ['sponsor_id' => 5, 'top' => 5, 'position' => 1, 'package' => 2],//id 7
        ]);

        CalculatorAuth::setToken(null);
        $clearResponse = $this->deleteJson(route('calculator.structure.clear', [
            'structure' => $structure->data->token_view,
        ]));

        $clearResponse->assertForbidden();
    }

    private function createNode(string $tokenEdit, int $rootId, int $position, ?string $username, int $packageId, array $headers): TestResponse
    {
        $data = [
            'structure_token' => $tokenEdit,
            'top_node_id' => $rootId,
            'position' => $position,
            'username' => $username,
            'package_id' => $packageId
        ];
        if (empty($username)) unset($data['username']);

        return $this->postJson(route('calculator.structure.node.create'), $data, $headers);
    }


}
