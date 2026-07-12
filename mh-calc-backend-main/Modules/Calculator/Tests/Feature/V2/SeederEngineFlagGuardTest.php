<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Database\Seeders\AgreementSeeder;
use Modules\Calculator\Database\Seeders\FeatureFlagSeeder;
use Modules\Calculator\Database\Seeders\ProductSeeder;
use Modules\Calculator\Models\FeatureFlag;
use Tests\TestCase;

/**
 * T15 (hardening) [ДЕНЬГИ, регресс-инвариант]: НИ ОДИН сидер из docker/start.sh
 * (ProductSeeder / FeatureFlagSeeder / AgreementSeeder) не пишет и не трогает флаг движка
 * mh_plan_v2_engine. Цементирует «рестарт/редеплой после cutover НЕ откатывает флип на V1»:
 * флаг живёт только в миграции (insertOrIgnore, deny-by-default OFF) и переключается вручную
 * координатором под owner-гейтом. Если будущая правка списка флагов протащит engine-флаг в
 * сидер — этот тест покраснеет.
 */
class SeederEngineFlagGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ENGINE_FLAG = 'mh_plan_v2_engine';

    /**
     * Эмуляция рестарта ПОСЛЕ cutover: флаг движка ON, прогоняем весь набор сидеров
     * start.sh — флаг обязан остаться ON (cutover не откатывается деплоем).
     */
    public function test_engine_flag_stays_on_across_all_startsh_seeders(): void
    {
        // Cutover выполнен: координатор включил движок.
        FeatureFlag::query()->updateOrCreate([
            'key' => self::ENGINE_FLAG,
        ], ['enabled' => true]);
        $this->assertTrue(
            (bool) FeatureFlag::query()->where('key', self::ENGINE_FLAG)->value('enabled'),
        );

        // Редеплой/рестарт: start.sh гоняет сидеры каждый раз.
        (new ProductSeeder())->run();
        (new FeatureFlagSeeder())->run();
        (new AgreementSeeder())->run();

        $this->assertTrue(
            (bool) FeatureFlag::query()->where('key', self::ENGINE_FLAG)->value('enabled'),
            'ни один сидер из start.sh не должен откатывать cutover-флаг движка на OFF',
        );
    }

    /**
     * FeatureFlagSeeder НЕ владеет engine-флагом: если удалить его и прогнать сидер,
     * сидер не должен его воссоздать (флаг — ответственность миграции, не сидера).
     */
    public function test_feature_flag_seeder_does_not_own_engine_flag(): void
    {
        FeatureFlag::query()->where('key', self::ENGINE_FLAG)->delete();

        (new FeatureFlagSeeder())->run();

        $this->assertNull(
            FeatureFlag::query()->where('key', self::ENGINE_FLAG)->first(),
            'FeatureFlagSeeder не должен создавать/трогать mh_plan_v2_engine',
        );

        // Свой набор (c1..c7 + ai_assistant) сидер по-прежнему досоздаёт — deny-by-default OFF.
        $this->assertTrue(
            (bool) FeatureFlag::query()->where('key', 'c1_notifications')->exists(),
        );
        $this->assertFalse(
            (bool) FeatureFlag::query()->where('key', 'c1_notifications')->value('enabled'),
        );
    }
}
