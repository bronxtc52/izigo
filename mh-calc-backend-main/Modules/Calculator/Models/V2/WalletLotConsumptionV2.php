<?php

namespace Modules\Calculator\Models\V2;

use Illuminate\Database\Eloquent\Model;

/**
 * mh-full-plan T02: строка трассировки движения по лоту (v2_wallet_lot_consumptions).
 * amount_cents > 0 всегда; направление задаёт reason (см. миграцию 2026_07_12_110300).
 */
class WalletLotConsumptionV2 extends Model
{
    protected $table = 'v2_wallet_lot_consumptions';

    public const UPDATED_AT = null;

    // «Из лота» (available уменьшился)
    public const REASON_ORDER_RESERVE = 'order_reserve';
    public const REASON_WITHDRAWAL_HOLD = 'withdrawal_hold';
    public const REASON_EXPIRY_TRANSFER = 'expiry_transfer';
    public const REASON_EXPIRY_ANNUL = 'expiry_annul';
    public const REASON_DEBIT = 'debit';
    // «Обратно в лот» (available вырос)
    public const REASON_RESERVE_RELEASE = 'reserve_release';
    public const REASON_REVERSAL = 'reversal';

    protected $fillable = [
        'lot_id', 'amount_cents', 'reason', 'tx_id',
        'reservation_id', 'withdrawal_request_id', 'created_at',
    ];

    protected $casts = ['amount_cents' => 'integer'];
}
