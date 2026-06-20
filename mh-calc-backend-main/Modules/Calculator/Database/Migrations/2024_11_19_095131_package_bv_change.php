<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->updatePackages(1, 'ru', 7650);
        $this->updatePackages(1, 'az', 135);
        $this->updatePackages(2, 'ru', 15300);
        $this->updatePackages(2, 'az', 270);
        $this->updatePackages(3, 'ru', 45900);
        $this->updatePackages(3, 'az', 810);
    }

    private function updatePackages(int $packageId, string $locale, int $bv): void
    {
        DB::table('calculator_package_volumes')
            ->where(['calculator_package_id' => $packageId, 'locale' => $locale])
            ->update(['bv' => $bv]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
