<?php

namespace Modules\Calculator\Models\V2;

use Illuminate\Database\Eloquent\Model;

/**
 * mh-full-plan T02: кэш субсчетов ОС/НС/БС участника (v2_member_accounts).
 * Rebuildable-проекция ledger_entries; правится ТОЛЬКО из WalletAccountsV2Service
 * под lockForUpdate (паттерн V1 MemberWallet/lockWallet).
 */
class MemberAccountV2 extends Model
{
    protected $table = 'v2_member_accounts';

    public const UPDATED_AT = 'updated_at';
    public const CREATED_AT = null;

    protected $fillable = [
        'member_id',
        'os_available_cents', 'os_held_cents',
        'ns_cents',
        'bs_available_cents', 'bs_held_cents',
        'currency', 'updated_at',
    ];

    protected $casts = [
        'os_available_cents' => 'integer',
        'os_held_cents' => 'integer',
        'ns_cents' => 'integer',
        'bs_available_cents' => 'integer',
        'bs_held_cents' => 'integer',
    ];
}
