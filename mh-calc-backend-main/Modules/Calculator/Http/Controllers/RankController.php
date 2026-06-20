<?php

namespace Modules\Calculator\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Calculator\Http\Resources\RankCollection;
use Modules\Calculator\Models\Rank;

/**
 * @group Calculator
 */
class RankController extends Controller
{
    /**
     * Ranks list
     *
     * @return RankCollection
     *
     * @response
     *     {
     *       "data": [
     *           {
     *               "id": 1,
     *               "sort": 1,
     *               "name": "Консультант"
     *           },
     *           {
     *               "id": 2,
     *               "sort": 2,
     *               "name": "Менеджер"
     *           },
     *           {
     *               "id": 3,
     *               "sort": 3,
     *               "name": "Бронзовый менеджер"
     *           },
     *           {
     *               "id": 4,
     *               "sort": 4,
     *               "name": "Серебряный менеджер"
     *           }
     *       ]
     *   }
     */
    public function index()
    {
        return resolve(RankCollection::class, ['resource' => Rank::orderBy('sort')->get()]);
    }

}
