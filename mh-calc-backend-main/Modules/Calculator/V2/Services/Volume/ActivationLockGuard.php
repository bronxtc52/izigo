<?php

namespace Modules\Calculator\V2\Services\Volume;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use RuntimeException;

/**
 * T03: страж дисциплины локов (amendments nice-to-have #5): advisory-lock
 * ACTIVATION_LOCK берёт ТОЛЬКО внешний оркестратор события (оплата/закрытие
 * периода/возврат/cutover); внутренние V2-сервисы лишь ПРОВЕРЯЮТ, что лок
 * уже удержан текущей сессией, — и падают громко, если инжест позвали в обход
 * транзакции оплаты (иначе гонка с конкурентным пересчётом сети).
 */
class ActivationLockGuard
{
    /** @param ?string $connection имя connection (null = дефолт); параметр — для тестов guard'а */
    public function __construct(private readonly ?string $connection = null)
    {
    }

    public function assertLockHeld(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return; // не-pgsql (юнит-контекст) — advisory-локов нет
        }

        $key = ActivationService::ACTIVATION_LOCK_KEY;
        $held = $db->selectOne(
            'SELECT 1 AS held FROM pg_locks '
            . 'WHERE locktype = ? AND pid = pg_backend_pid() AND granted '
            . 'AND classid = ? AND objid = ?',
            ['advisory', ($key >> 32) & 0xFFFFFFFF, $key & 0xFFFFFFFF]
        );

        if ($held === null) {
            throw new RuntimeException(
                'V2 volume-инжест вызван без advisory-lock активаций: '
                . 'оркестратор оплаты обязан взять ACTIVATION_LOCK до ledger/V2-записей'
            );
        }
    }
}
