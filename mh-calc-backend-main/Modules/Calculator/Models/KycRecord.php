<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KYC-запись участника (Фаза 4). Один актуальный статус на участника.
 *
 * @property int $id
 * @property int $member_id
 * @property string $source
 * @property ?array $documents
 * @property string $review_status
 * @property ?int $reviewed_by
 */
class KycRecord extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'member_id',
        'source',
        'documents',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
    ];

    protected $casts = [
        'member_id' => 'integer',
        // Шифрование PII at-rest (APP_KEY). До реальной расшифровки Passport (Фаза 5)
        // здесь лежит зашифрованный payload/ссылки; cast гарантирует, что в БД — шифртекст.
        'documents' => 'encrypted:array',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
