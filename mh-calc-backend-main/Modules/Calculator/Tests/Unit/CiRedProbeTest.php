<?php

namespace Modules\Calculator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Одноразовая проверка приёмки №4: CI-job test обязан падать на красном тесте. Ветка будет удалена. */
class CiRedProbeTest extends TestCase
{
    public function testIntentionallyRed(): void
    {
        $this->fail('намеренно красный тест — проверка CI-гейта');
    }
}
