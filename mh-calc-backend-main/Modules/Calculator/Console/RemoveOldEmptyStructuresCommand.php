<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\StructureService;

class RemoveOldEmptyStructuresCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calculator:remove-old-empty';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Удалит структуры с max_node_id = 1 созданные более суток назад';

    /**
     * Execute the console command.
     *
     * @return void
     * @throws \Exception
     */
    public function handle(StructureService $service)
    {
        $service->deleteAllEmpty();
    }
}
