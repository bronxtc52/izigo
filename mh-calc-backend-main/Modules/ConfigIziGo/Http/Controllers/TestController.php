<?php

namespace Modules\ConfigIziGo\Http\Controllers;

use App\Http\Controllers\Controller;

/**
 * @group ConfigIziGo
 */
class TestController extends Controller
{
    /**
     *
     * @return void
     */
    public function dev1()
    {
        $a = [
            'v' => 1,
            'ch' => [
                0 => ['v' => 2, 'ch' => [
                    0 => ['v' => 2, 'ch' => []],
                    1 => ['v' => 3, 'ch' => []]
                ]]
                ,
                1 => ['v' => 3, 'ch' => [
                    0 => ['v' => 2, 'ch' => []],
                    1 => ['v' => 3, 'ch' => []]
                ]]
            ]
        ];

        dd($this->calc($a));

    }

    private function calc($a)
    {
        $res = $a['v'];
        foreach ($a['ch'] as $ch)
        {
            $res += $this->calc($ch);
        }
        return $res;
    }

}
