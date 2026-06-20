<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Calculator\Dto\NodeCreateData;
use Modules\Calculator\Dto\NodeDeleteData;
use Modules\Calculator\Dto\NodeUpdateData;
use Modules\Calculator\Dto\SetStructurePackageData;
use Modules\Calculator\Dto\UserNodeData;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Structure\Node;
use Modules\Calculator\Models\Structure\Structure;

class StructureService
{

    public function createUserName(int $id): string
    {
        return __("calculator::structure.default_username", [
            'id' => $id
        ]);
    }

    public function createToken(): string
    {
        $uniqueString = uniqid('', true) . Str::random(40) . microtime(true);
        return hash('sha256', $uniqueString);
    }

    /**
     * Создание структуры
     *
     * @param UserNodeData $data
     * @param int $userId
     * @return Structure
     */
    public function create(UserNodeData $data, int $userId): Structure
    {
        $root = new Node(1, $data->username ?? $this->createUserName(1));
        $root->package_id = $data->package_id ?? 0;
        $root->sponsor_id = $data->sponsor_id ?? 0;

        /** @var NodeSerializer $serializer */
        $serializer = resolve(NodeSerializer::class);

        /** @var Structure $structure */
        $structure = Structure::create([
            'calculator_user_id' => $userId,
            'token_view' => $this->createToken(),
            'token_edit' => $this->createToken(),
            'data' => $serializer->serialize($root),
            'max_node_id' => $root->id
        ]);

        $structure->visitByEditLink = true;

        return $structure;
    }

    /**
     * ТЗ:
     * Пользователь выбирает любого существующего участника в структуре и по кнопке “Действия” добавляет нового участника.
     * В добавленном блоке пользователя для нового участника можно указать:
     * Имя - редактируемое поле, по умолчанию содержит значение “Пользователь [ID]”
     * Спонсора - выбирается в выпадающем списке из уже добавленных участников
     * Пакет - выбирается в выпадающем списке из существующих вариантов
     * После добавления участника структура обновляется, пересчитываются бонусы и товарооборот, может быть присвоен “Статус”.
     *
     * @param NodeCreateData $data
     * @return Structure
     * @throws \Exception
     */
    public function addNode(NodeCreateData $data): Structure
    {
        return DB::transaction(function () use ($data) {
            $structure = $data->structure;

            $root = $structure->getRoot();
            $topNode = $structure->getNodeById($root, $data->top_node_id);

            $newNodeId = $structure->max_node_id + 1;
            $newNode = new Node($newNodeId, $data->username ?? $this->createUserName($newNodeId));

            $newNode->pos = $data->position;
            $newNode->package_id = $data->package_id;
            $newNode->sponsor_id = empty($data->sponsor_id) ? $topNode->id : $data->sponsor_id;
            $newNode->sponsor = $topNode?->findSponsor($newNode->sponsor_id);

            $this->addNodeInFirstLevel($structure, $topNode, $newNode);
            //$this->addNodeRecursive([$topNode], $newNode);

            /** @var NodeSerializer $serializer */
            $serializer = resolve(NodeSerializer::class);
            $structure->data = $serializer->serialize($root);
            $structure->max_node_id = $newNode->id;
            $structure->save();
            return $structure;
        }, config('app.transaction_attempts'));
    }

    public function updateNode(NodeUpdateData $data): Structure
    {
        return DB::transaction(function () use ($data) {
            $structure = $data->structure;

            $root = $structure->getRoot();
            $node = $structure->getNodeById($root, $data->node_id);
            $node->name = $data->username;
            $node->package_id = $data->package_id;

            if ($data->sponsor_id != $node->sponsor_id) {
                $oldSponsor = $node->sponsor;
                if ($oldSponsor) {
                    $oldSponsor->invited_count -= 1;
                }

                $node->sponsor_id = $data->sponsor_id;
                if ($node->sponsor_id) {
                    $node->sponsor = $node->parent?->findSponsor($data->sponsor_id);
                    $node->sponsor->invited_count += 1;
                }
            }

            return $this->saveStructure($structure, $root);

        }, config('app.transaction_attempts'));
    }

    /**
     *
     * Установка выбранного контракта указанному пользователю и всем его нижестоящим.
     * Если не указан id ноды, то полностью всей структуре.
     * Если указан id ноды, то ноде и всей его структуре.
     *
     * @param SetStructurePackageData $data
     * @return Structure
     */
    public function setStructurePackage(SetStructurePackageData $data):Structure
    {
        return DB::transaction(function () use ($data) {
            $structure = $data->structure;

            $root = $structure->getRoot();

            $nodeForChange = $data->top_node_id ? $structure->getNodeById($root, $data->top_node_id) : $root;
            $this->setStructurePackageRecursive($nodeForChange, $data->package_id);

            return $this->saveStructure($structure, $root);

        }, config('app.transaction_attempts'));
    }

    private function saveStructure(Structure &$structure, Node $root):Structure
    {
        /** @var NodeSerializer $serializer */
        $serializer = resolve(NodeSerializer::class);
        $structure->data = $serializer->serialize($root);
        $structure->save();

        return $structure;
    }

    private function setStructurePackageRecursive(Node $node, ?int $packageId):void
    {
        $node->package_id = $packageId;

        foreach ($node->children as $child) {
            if ($child) {
                $this->setStructurePackageRecursive($child, $packageId);
            }
        }
    }

    public function deleteNode(NodeDeleteData $data): Structure
    {
        return DB::transaction(function () use ($data) {
            $structure = $data->structure;

            $root = $structure->getRoot();
            $node = $structure->getNodeById($root, $data->node_id);

            $node->sponsor->invited_count -= 1;
            $node->parent->children[$node->pos - 1] = null;

            return $this->saveStructure($structure, $root);

        }, config('app.transaction_attempts'));
    }

    public function clear(?Structure $structure): Structure
    {
        return DB::transaction(function () use ($structure) {

            $root = $structure->getRoot();
            $root->package_id = Package::orderBy('sort')->limit(1)->value('id');
            $root->invited_count = 0;

            for ($branchIndex = 0; $branchIndex < Structure::WIDTH; $branchIndex++) {
                $root->children[$branchIndex] = null;
            }

            return $this->saveStructure($structure, $root);

        }, config('app.transaction_attempts'));
    }

    /**
     * @param Structure $structure
     * @param Node $topNode
     * @param Node $newNode
     * @return void
     * @throws \Exception
     */
    private function addNodeInFirstLevel(Structure $structure, Node $topNode, Node $newNode): void
    {
        if (!empty($topNode->children[$newNode->pos - 1])) {
            throw new \Exception(__CLASS__ . ' ' . __FUNCTION__ . ' position busy.');
        }

        $topNode->children[$newNode->pos - 1] = $newNode;
        $newNode->parent_id = $topNode->id;
        $newNode->parent = $topNode;

        $sponsorNode = $structure->getNodeById($structure->getRoot(), $newNode->sponsor_id);
        $sponsorNode->invited_count += 1;
    }

    public function deleteAllEmpty(): void
    {
        Structure::where('created_at', '<=', now()->subDay())->where('max_node_id', 1)->delete();
    }

    /**
     * Постановка переливом.
     * Решили так не делать, пусть только вручную будет выбор.
     *
     * @param Node[] $topNodeList
     * @param Node $newNode
     * @return void
     * @throws \Exception
     */
    private function addNodeRecursive(array $topNodeList, Node $newNode): void
    {
        $candidatesTopList = [];
        foreach ($topNodeList as $topNode) {
            for ($index = 0; $index < Structure::WIDTH; $index++) {
                if (empty($topNode->children[$index])) {
                    $topNode->children[$index] = $newNode;
                    $newNode->parent_id = $topNode->id;
                    $newNode->parent = $topNode;
                    $newNode->pos = $index + 1;
                    return;
                } else {
                    $candidatesTopList[] = $topNode->children[$index];
                }
            }
        }

        $this->addNodeRecursive($candidatesTopList, $newNode);
    }
}
