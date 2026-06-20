<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up()
    {
        $this->createStructure();
        $this->createPackages();
        $this->createRanks();
    }

    public function down()
    {
        Schema::dropIfExists('calculator_structures');
        Schema::dropIfExists('calculator_ranks');
        Schema::dropIfExists('calculator_package_volumes');
        Schema::dropIfExists('calculator_packages');
    }

    /**
     * @return void
     */
    public function createStructure(): void
    {
        Schema::create('calculator_structures', function (Blueprint $table) {
            $table->id();
            $table->string('token_edit', 64)->unique();
            $table->string('token_view', 64)->unique();
            $table->integer('max_node_id',)->default(1);
            //$table->jsonb('data');
            $table->text('data');
            $table->timestamps();
        });
    }

    private function createRanks(): void
    {
        Schema::create('calculator_ranks', function (Blueprint $table) {
            $table->id();
            $table->integer('sort')->index();
            $table->string('alias')->index();
        });

        foreach ([
                     'consultant',
                     'manager',
                     'manager_bronze',
                     'manager_silver'] as $index => $alias) {
            DB::table('calculator_ranks')->insert([
                'sort' => $index + 1,
                'alias' => $alias
            ]);
        }
    }

    /**
     * @return void
     */
    public function createPackages(): void
    {
        Schema::create('calculator_packages', function (Blueprint $table) {
            $table->id();
            $table->integer('sort')->index();
        });

        Schema::create('calculator_package_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculator_package_id')->constrained()->onDelete('cascade');
            $table->string('locale', 5)->index();
            $table->float('pv', 20, 2)->default(0);
            $table->float('bv', 20, 2)->default(0);

            $table->unique(['calculator_package_id', 'locale']);
        });

        $volumesMap = $this->getPackageVolumes();
        foreach ($volumesMap as $sort => $mapByLocale) {
            $packageId = DB::table('calculator_packages')->insertGetId([
                'sort' => $sort
            ]);
            foreach ($mapByLocale as $locale => $data) {
                DB::table('calculator_package_volumes')->insert([
                    'calculator_package_id' => $packageId,
                    'locale' => $locale,
                    'pv' => $data['pv'],
                    'bv' => $data['bv'],
                ]);
            }
        }
    }

    private function getPackageVolumes(): array
    {
        return [
            1 => [
                'kk' => ['pv' => 100, 'bv' => 42120],
                'mn' => ['pv' => 100, 'bv' => 312840],
                'ru' => ['pv' => 100, 'bv' => 6750],
                'uz' => ['pv' => 100, 'bv' => 1035000],
                'ky' => ['pv' => 100, 'bv' => 8100],
                'az' => ['pv' => 100, 'bv' => 153],
            ],
            2 => [
                'kk' => ['pv' => 200, 'bv' => 84240],
                'mn' => ['pv' => 200, 'bv' => 625680],
                'ru' => ['pv' => 200, 'bv' => 13500],
                'uz' => ['pv' => 200, 'bv' => 2070000],
                'ky' => ['pv' => 200, 'bv' => 16200],
                'az' => ['pv' => 200, 'bv' => 306],
            ],
            3 => [
                'kk' => ['pv' => 600, 'bv' => 252720],
                'mn' => ['pv' => 600, 'bv' => 1877040],
                'ru' => ['pv' => 600, 'bv' => 40500],
                'uz' => ['pv' => 600, 'bv' => 6210000],
                'ky' => ['pv' => 600, 'bv' => 48600],
                'az' => ['pv' => 600, 'bv' => 918],
            ]];
    }
};
