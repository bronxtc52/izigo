<?php

namespace Modules\Calculator\V2\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * V2 T04: immutable-снапшот входов расчётного прогона (v2_calc_snapshots).
 * Создаётся SnapshotService::freeze() ДО исполнения шагов закрытия; апдейта не
 * существует by design — попытка update кидает LogicException (guard в booted).
 * Секции payload расширяют close-steps T06/T09/T11 ТОЛЬКО через
 * SnapshotService::addSection() до freeze, не через модель.
 *
 * @property int $id
 * @property int $run_id
 * @property array $payload
 * @property string $payload_hash
 */
class CalcSnapshot extends Model
{
    protected $table = 'v2_calc_snapshots';

    /** Только created_at: снапшот никогда не обновляется. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'run_id',
        'payload',
        'payload_hash',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        // ДЕНЬГИ: снапшот — доказательство входов закрытия; менять его нельзя никому.
        static::updating(function () {
            throw new \LogicException('CalcSnapshot is immutable: снапшоты V2 не изменяются.');
        });
        static::deleting(function () {
            throw new \LogicException('CalcSnapshot is immutable: снапшоты V2 не удаляются.');
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CalcRun::class, 'run_id');
    }
}
