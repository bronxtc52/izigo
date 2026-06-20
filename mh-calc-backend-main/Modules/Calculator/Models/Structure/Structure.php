<?php

namespace Modules\Calculator\Models\Structure;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\CalculatorUserToken;
use Modules\Calculator\Services\NodeSerializer;


/**
 * @property int $id
 * @property int $calculator_user_id
 * @property int $max_node_id
 * @property int $root_last_rank
 * @property string $token_edit
 * @property string $token_view
 * @property string $data
 * @property \Illuminate\Support\Carbon|null $created_at Дата и время создания записи.
 * @property \Illuminate\Support\Carbon|null $updated_at Дата и время последнего обновления записи.
 *
 * @property-read CalculatorUser $user
 */
class Structure extends Model
{
    const WIDTH = 2;

    protected $table = 'calculator_structures';

    protected $fillable = [
        'calculator_user_id',
        'token_edit',
        'token_view',
        'data',
        'max_node_id',
        'root_last_rank',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    private ?Node $root = null;

    public ?Node $lastNodeWithPackage = null;

    public bool $visitByEditLink = false;

    public array $rootProfitByNodes = [];

    public function addRootProfit(float $amount):void
    {
        if ($this->lastNodeWithPackage)
        {
            $this->rootProfitByNodes[$this->lastNodeWithPackage->id] = ($this->rootProfitByNodes[$this->lastNodeWithPackage->id] ?? 0) + $amount;
        }
    }

    public function getRootProfitByNodeLast():float
    {
        return $this->rootProfitByNodes[$this->lastNodeWithPackage?->id] ?? 0;
    }

    /**
     * Редактировать и свои и чужие структуры могут все авторизованные пользователи,
     * у которых есть ссылка на редактирование.
     *
     * @param CalculatorUserToken|null $userToken
     * @return bool
     */
    public function canEdit(?CalculatorUserToken $userToken): bool
    {
        if (!$userToken || !$userToken->isValid())
        {
            return false;
        }

        return $this->visitByEditLink || $this->calculator_user_id == $userToken->calculator_user_id;
    }

    /**
     * @param string|null $token
     * @param int $exceptionIfNotFound если > 0, то отдаст код ошибки
     * @return Structure|null
     */
    public static function findByToken(?string $token, int $exceptionIfNotFound = 0): ?Structure
    {
        /** @var Structure $structure */
        $structure = $token ? self::query()
            ->where('token_view', $token)
            ->orWhere('token_edit', $token)
            ->first() : null;

        if (!$structure && $exceptionIfNotFound) {
            abort($exceptionIfNotFound);
        }

        if ($structure->token_edit == $token) {
            $structure->visitByEditLink = true;
        }

        return $structure;
    }

    public function getNodeById(?Node $node, ?int $nodeId): ?Node
    {
        if (!$node || !$nodeId) return null;

        if ($node->id == $nodeId) {
            return $node;
        }

        foreach ($node->children as $index => $child) {
            $result = $child ? $this->getNodeById($node->children[$index], $nodeId) : null;
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    public function getRoot(): Node
    {
        if (!$this->root) {
            /** @var NodeSerializer $service */
            $service = resolve(NodeSerializer::class);
            $this->root = $service->deserialize($this->data, null);
        }
        return $this->root;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(CalculatorUser::class, 'calculator_user_id', 'id');
    }

    public function hasParentOrSelf(int $topNodeId, int $wantSponsorNodeId): bool
    {
        $node = $this->getNodeById($this->getRoot(), $topNodeId);
        while ($node) {
            if ($node->id == $wantSponsorNodeId) {
                return true;
            }
            $node = $node->parent;
        }
        return false;
    }

    public function isBusyUsername(string $username, int $excludeNodeId): bool
    {
        return $this->getRoot()->isBusyUsername($username, $excludeNodeId);
    }

    public function isPositionBusy(?int $topNodeId, int $position): bool
    {
        $node = $this->getNodeById($this->getRoot(), $topNodeId);
        return $node && ($node->children[$position - 1] ?? null);
    }

    /**
     * Ссылка на последнюю добавленную ноду, если она с контрактом
     * и повлияла на итоговый баланс root
     *
     * @param Node $addedNode
     * @return void
     */
    public function setLastNodeWithPackage(Node $addedNode): void
    {
        if ($addedNode->parent_id && $addedNode->package_id) {
            $this->lastNodeWithPackage = $addedNode;
        }
    }

}
