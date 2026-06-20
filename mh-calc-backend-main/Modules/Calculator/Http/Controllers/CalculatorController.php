<?php

namespace Modules\Calculator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Http\Requests\NodeCreateRequest;
use Modules\Calculator\Http\Requests\NodeDeleteRequest;
use Modules\Calculator\Http\Requests\NodeUpdateRequest;
use Modules\Calculator\Http\Requests\SetStructurePackageRequest;
use Modules\Calculator\Http\Requests\StructureCreateRequest;
use Modules\Calculator\Http\Resources\LogItemCollection;
use Modules\Calculator\Http\Resources\NotifyItemCollection;
use Modules\Calculator\Http\Resources\StructureLightCollection;
use Modules\Calculator\Http\Resources\StructureNodeDetails;
use Modules\Calculator\Http\Resources\StructureResource;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Services\CalculatorService;
use Modules\Calculator\Services\StructureService;

/**
 * @group Calculator
 */
class CalculatorController extends Controller
{
    /**
     * Structure create
     *
     * Создание новой структуры.
     *
     * В информации о структуре отдаю два токена:
     * "token_view": "18d486a6b0b47cd0...",
     * "token_edit": "6b0d3c91027144ba...",
     *
     * Фронт составляет с этими токенами две ссылки:
     * с token_edit - ссылка на просмотр и редактирование.
     * Создатель может передать ее куму-то, кто тоже будет редактировать структуру.
     * token_view - ссылка только на просмотр.
     * Если у человека такая ссылка, то он не может редактировать структуру.
     * Ему в информации о структуре в поле token_edit будет приходить null.
     *
     * По какой бы ссылке не переходил человек, фронт передает мне токен из этой ссылки
     * в get запрос, например:
     * {{url}}/api/{{v}}/calculator/structure/974e_и_т_д_какой-то_токен_тут
     * А бек уже сам определяет уровень доступа.
     *
     * Если token_edit пустой, то человеку не нужно отображать кнопки редактирования структуры.
     *
     * То же и для POST
     * {{url}}/api/{{v}}/calculator/structure/node/create
     * тут будет параметр: structure_token
     * Если в него передать токен для чтения, то бек вернет 403 "доступ запрещен".
     *
     * Фронту:
     * в левой части экрана,
     * вместо KZT выводить название выбранной пользователем валюты для этого блока:
     * Пример: Вы оформили покупку на сумму 54 000 BV.
     * Ваш бонус составит:  5 400 KZT”
     *
     * Для сумм бонусов в левой части экрана брать информацию из root:
     * "bonus_rank_sum": 0,
     * "bonus_rank_sum_format": "0.00 RUB",
     * "bonus_binary_sum": 0,
     * "bonus_binary_sum_format": "0.00 RUB",
     * "bonus_referral_sum": 0,
     * "bonus_referral_sum_format": "0.00 RUB",
     * "bonus_referral_sum_level_1": 0,
     * "bonus_referral_sum_level_1_format": "0.00 RUB",
     * "bonus_referral_sum_level_2": 0,
     * "bonus_referral_sum_level_2_format": "0.00 RUB",
     * "bonus_leader_sum": 0,
     * "bonus_leader_sum_format": "0.00 RUB",
     *
     * @response {
     *      "status": "error",
     *      "message": "No token provided",
     *      "need_login": true
     *  }
     *
     * @response {
     *     "status": "error",
     *     "message": "Invalid or expired token",
     *     "need_login": true
     * }
     *
     * @response
     * {
     *      "data": {
     *          "lang": {
     *              "code": "kk",
     *              "name": "Қазақстан - теңге",
     *              "currency": "KZT",
     *              "country": "Қазақстан",
     *              "language": "Казахский"
     *          },
     *          "currency": {
     *              "code": "ru",
     *              "name": "Ресей - RUB",
     *              "currency": "RUB",
     *              "country": "Ресей",
     *              "language": "Русский"
     *          },
     *          "can_edit": true,
     *          "token_view": "a7f47a0bb4bf8dd62f3fb532b833260230ebf6be787036fe421411d22dc9e2aa",
     *          "token_edit": "0b280a0bdf950c0046bae03fb787ebcee722f8dc3362c2e52ba6125c0060db48",
     *          "root": {
     *              "id": 1,
     *              "pos": 0,
     *              "name": "Пользователь 1",
     *              "parent_id": 0,
     *              "possible_sponsor_list": {},
     *              "possible_sponsor_list_for_child": {
     *                  "1": "Пользователь 1"
     *              },
     *              "sponsor_id": 0,
     *              "sponsor": null,
     *              "package_id": 1,
     *              "package_name": "Start kk",
     *              "package_pv": "100",
     *              "package_bv": "6750",
     *              "rank_id": 0,
     *              "rank_name": "Кеңесші",
     *              "invited_count": 0,
     *              "pv_left": 0,
     *              "pv_left_format": "0.00 PV",
     *              "pv_right": 0,
     *              "pv_right_format": "0.00 PV",
     *              "bv_left": 0,
     *              "bv_left_format": "0.00 BV",
     *              "bv_right": 0,
     *              "bv_right_format": "0.00 BV",
     *              "bonus_rank_sum": 0,
     *              "bonus_rank_sum_format": "0.00 RUB",
     *              "bonus_binary_sum": 0,
     *              "bonus_binary_sum_format": "0.00 RUB",
     *              "bonus_referral_sum": 0,
     *              "bonus_referral_sum_format": "0.00 RUB",
     *              "bonus_referral_sum_level_1": 0,
     *              "bonus_referral_sum_level_1_format": "0.00 RUB",
     *              "bonus_referral_sum_level_2": 0,
     *              "bonus_referral_sum_level_2_format": "0.00 RUB",
     *              "bonus_leader_sum": 0,
     *              "bonus_leader_sum_format": "0.00 RUB",
     *              "children": [
     *                  null,
     *                  null
     *              ]
     *          }
     *      },
     *      "log": []
     *  }
     * @param StructureCreateRequest $request
     * @param StructureService $service
     * @return StructureResource
     */
    public function structureCreate(StructureCreateRequest $request, StructureService $service): StructureResource
    {
        $userToken = CalculatorAuth::token();

        $structure = $service->create($request->getDto(), $userToken->user->id);
        return $this->structureCalculate($structure);
    }

    /**
     * Structure add node
     *
     * Добавление нового пользователя в бинар
     *
     *
     * @response {
     *      "status": "error",
     *      "message": "No token provided",
     *      "need_login": true
     *  }
     *
     * @response
     * {
     *      "data": {
     *          "lang": {
     *              "code": "ru",
     *              "name": "Россия — RUB",
     *              "currency": "RUB",
     *              "country": "Россия",
     *              "language": "Русский"
     *          },
     *          "currency": {
     *              "code": "ru",
     *              "name": "Россия — RUB",
     *              "currency": "RUB",
     *              "country": "Россия",
     *              "language": "Русский"
     *          },
     *          "can_edit": true,
     *          "token_view": "974e595694f4a1ff43b45313b1755708b237b0ed32a56a01791550e9c794d324",
     *          "token_edit": "5328044918ff01501a2c8031ceb65937c8a41efec2cd13109c3743391861485e",
     *          "root": {
     *              "id": 1,
     *              "pos": 0,
     *              "name": "Пользователь 1",
     *              "parent_id": 0,
     *              "possible_sponsor_list": {},
     *              "possible_sponsor_list_for_child": {
     *                  "1": "Пользователь 1"
     *              },
     *              "sponsor_id": 0,
     *              "sponsor": null,
     *              "package_id": 1,
     *              "package_name": "Start",
     *              "package_pv": "100",
     *              "package_bv": "6750",
     *              "rank_id": 0,
     *              "rank_name": "Консультант",
     *              "invited_count": 0,
     *              "pv_left": 400,
     *              "pv_left_format": "400.00 PV",
     *              "pv_right": 2400,
     *              "pv_right_format": "2 400.00 PV",
     *              "bv_left": 27000,
     *              "bv_left_format": "27 000.00 BV",
     *              "bv_right": 162000,
     *              "bv_right_format": "162 000.00 BV",
     *              "bonus_rank_sum": 0,
     *              "bonus_rank_sum_format": "0.00 RUB",
     *              "bonus_binary_sum": 0,
     *              "bonus_binary_sum_format": "0.00 RUB",
     *              "bonus_referral_sum": 4050,
     *              "bonus_referral_sum_format": "4 050.00 RUB",
     *              "bonus_referral_sum_level_1": 4050,
     *              "bonus_referral_sum_level_1_format": "4 050.00 RUB",
     *              "bonus_referral_sum_level_2": 0,
     *              "bonus_referral_sum_level_2_format": "0.00 RUB",
     *              "bonus_leader_sum": 0,
     *              "bonus_leader_sum_format": "0.00 RUB",
     *              "children": [
     *                  {
     *                      "id": 2,
     *                      "pos": 1,
     *                      "name": "Пользователь 2",
     *                      "parent_id": 1,
     *                      "possible_sponsor_list": {
     *                          "1": "Пользователь 1"
     *                      },
     *                      "possible_sponsor_list_for_child": {
     *                          "1": "Пользователь 1",
     *                          "2": "Пользователь 2"
     *                      },
     *                      "sponsor_id": 1,
     *                      "sponsor": "Пользователь 1",
     *                      "package_id": 2,
     *                      "package_name": "Business",
     *                      "package_pv": "200",
     *                      "package_bv": "13500",
     *                      "rank_id": 0,
     *                      "rank_name": "Менеджер",
     *                      "invited_count": 0,
     *                      "pv_left": 200,
     *                      "pv_left_format": "200.00 PV",
     *                      "pv_right": 0,
     *                      "pv_right_format": "0.00 PV",
     *                      "bv_left": 13500,
     *                      "bv_left_format": "13 500.00 BV",
     *                      "bv_right": 0,
     *                      "bv_right_format": "0.00 BV",
     *                      "bonus_rank_sum": 0,
     *                      "bonus_rank_sum_format": "0.00 RUB",
     *                      "bonus_binary_sum": 0,
     *                      "bonus_binary_sum_format": "0.00 RUB",
     *                      "bonus_referral_sum": 0,
     *                      "bonus_referral_sum_format": "0.00 RUB",
     *                      "bonus_referral_sum_level_1": 0,
     *                      "bonus_referral_sum_level_1_format": "0.00 RUB",
     *                      "bonus_referral_sum_level_2": 0,
     *                      "bonus_referral_sum_level_2_format": "0.00 RUB",
     *                      "bonus_leader_sum": 0,
     *                      "bonus_leader_sum_format": "0.00 RUB",
     *                      "children": [
     *                          null,
     *                          null
     *                      ]
     *                  },
     *                  {
     *                      "id": 3,
     *                      "pos": 2,
     *                      "name": "Пользователь 3",
     *                      "parent_id": 1,
     *                      "possible_sponsor_list": {
     *                          "1": "Пользователь 1"
     *                      },
     *                      "possible_sponsor_list_for_child": {
     *                          "1": "Пользователь 1",
     *                          "3": "Пользователь 3"
     *                      },
     *                      "sponsor_id": 1,
     *                      "sponsor": "Пользователь 1",
     *                      "package_id": 2,
     *                      "package_name": "Business",
     *                      "package_pv": "200",
     *                      "package_bv": "13500",
     *                      "rank_id": 1,
     *                      "rank_name": "Менеджер",
     *                      "invited_count": 6,
     *                      "pv_left": 1400,
     *                      "pv_left_format": "1 400.00 PV",
     *                      "pv_right": 800,
     *                      "pv_right_format": "800.00 PV",
     *                      "bv_left": 94500,
     *                      "bv_left_format": "94 500.00 BV",
     *                      "bv_right": 54000,
     *                      "bv_right_format": "54 000.00 BV",
     *                      "bonus_rank_sum": 0,
     *                      "bonus_rank_sum_format": "0.00 RUB",
     *                      "bonus_binary_sum": 2700,
     *                      "bonus_binary_sum_format": "2 700.00 RUB",
     *                      "bonus_referral_sum": 14850,
     *                      "bonus_referral_sum_format": "14 850.00 RUB",
     *                      "bonus_referral_sum_level_1": 14850,
     *                      "bonus_referral_sum_level_1_format": "14 850.00 RUB",
     *                      "bonus_referral_sum_level_2": 0,
     *                      "bonus_referral_sum_level_2_format": "0.00 RUB",
     *                      "bonus_leader_sum": 0,
     *                      "bonus_leader_sum_format": "0.00 RUB",
     *                      "children": [
     *                          null,
     *                          null,
     *                      ]
     *                   }
     *          },
     *         "last_added_node": {
     *              "id": 3,
     *              "pos": 1,
     *              "name": "Пользователь 3",
     *              "parent_id": 1,
     *              "sponsor_id": 1,
     *              "sponsor": "Пайдаланушы 1",
     *              "package_id": 1,
     *              "package_name": "Start",
     *              "package_pv": "100",
     *              "package_bv": "42120",
     *              "rank_id": 0,
     *              "rank_name": null,
     *              "purchase_amount": "42120",
     *              "purchase_amount_format": "42 120.00 KZT"
     *          }
     *      },
     *      "log": [
     *          {
     *              "title": "Постановка пользователя",
     *              "initiator_info": "[2, Пользователь 2]",
     *              "initiator_name": "Пользователь 2",
     *              "congratulation": "Поздравляем! 🎉",
     *              "events_start": "При постановке Пользователь 2:",
     *              "events": [
     *                  "Вам добавлено +200.00 PV и +13 500.00 BV в левую ветку.",
     *                  "Реферальный бонус 10% с уровня 1 в размере 1 350.00 RUB!"
     *              ],
     *              "events_finish": "Великолепно!"
     *          },
     *          {
     *              "title": "Постановка пользователя",
     *              "initiator_info": "[3, Пользователь 3]",
     *              "initiator_name": "Пользователь 3",
     *              "congratulation": "Поздравляем! 🎉",
     *              "events_start": "При постановке Пользователь 3:",
     *              "events": [
     *                  "Вам добавлено +200.00 PV и +13 500.00 BV в правую ветку.",
     *                  "Реферальный бонус 10% с уровня 1 в размере 1 350.00 RUB!"
     *              ],
     *              "events_finish": "Отличный результат!"
     *          }
     *      ]
     *  }
     * @param NodeCreateRequest $request
     * @param StructureService $service
     * @return StructureResource
     * @throws \Exception
     */
    public function addNode(NodeCreateRequest $request, StructureService $service): StructureResource
    {
        $structure = $service->addNode($request->getDto());
        return $this->structureCalculate($structure);
    }

    /**
     * Structure set package
     *
     * Установка выбранного контракта указанному пользователю и всем его нижестоящим.
     * Если не указан id ноды, то полностью всей структуре.
     * Если указан id ноды, то ноде и всей его структуре.
     *
     * Пример ответа: см Structure add node
     *
     * @response {
     *      "status": "error",
     *      "message": "No token provided",
     *      "need_login": true
     *  }
     *
     * @param SetStructurePackageRequest $request
     * @param StructureService $service
     * @return StructureResource
     * @throws \Exception
     */
    public function setStructurePackage(SetStructurePackageRequest $request, StructureService $service): StructureResource
    {
        $structure = $service->setStructurePackage($request->getDto());
        return $this->structureCalculate($structure);
    }

    /**
     * Structure update node
     *
     * Редактирование пользователя в бинаре
     *
     * @response {
     *     "status": "error",
     *     "message": "No token provided",
     *     "need_login": true
     * }
     *
     * Пример ответа: см Structure add node
     * @param NodeUpdateRequest $request
     * @param StructureService $service
     * @return StructureResource
     */
    public function updateNode(NodeUpdateRequest $request, StructureService $service): StructureResource
    {
        $structure = $service->updateNode($request->getDto());
        return $this->structureCalculate($structure);
    }

    /**
     * Structure delete node
     *
     * Удаление ноды со всей веткой.
     * Пример ответа: см Structure add node
     *
     * @param NodeDeleteRequest $request
     * @param StructureService $service
     * @return StructureResource
     */
    public function deleteNode(NodeDeleteRequest $request, StructureService $service): StructureResource
    {
        $structure = $service->deleteNode($request->getDto());
        return $this->structureCalculate($structure);
    }

    /**
     * Structure clear
     *
     * Очистка структуры
     * Пример ответа: см Structure add node
     *
     * @param string $token
     * @param StructureService $service
     * @return StructureResource
     */
    public function structureClear(string $token, StructureService $service): StructureResource
    {
        $structure = Structure::findByToken($token, Response::HTTP_NOT_FOUND);

        if (!$structure->canEdit(CalculatorAuth::token())) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $structure = $service->clear($structure);
        return $this->structureCalculate($structure);
    }

    /**
     * Structure get
     *
     * Получение данных структуры
     *
     * Пример ответа: см Structure add node
     *
     * @param string $token
     * @return StructureResource
     */
    public function getStructure(string $token): StructureResource
    {
        $structure = Structure::findByToken($token, Response::HTTP_NOT_FOUND);
        return $this->structureCalculate($structure);
    }

    /**
     * Get node details
     *
     * Получение детализированных данных по ноде
     *
     * @response {
     *     "status": "error",
     *     "message": "No token provided",
     *     "need_login": true
     * }
     *
     * @response
     * {
     *     "data": {
     *         "id": 2,
     *         "pos": 1,
     *         "name": "User2",
     *         "parent_id": 1,
     *         "possible_sponsor_list": {
     *             "1": "Пользователь 1"
     *         },
     *         "possible_sponsor_list_for_child": {
     *             "1": "Пользователь 1",
     *             "2": "User2"
     *         },
     *         "sponsor_id": 1,
     *         "sponsor": "Пользователь 1",
     *         "package_id": 2,
     *         "package_name": "Business",
     *         "package_pv": "200",
     *         "package_bv": "13500",
     *         "rank_id": 1,
     *         "rank_name": "Консультант",
     *         "invited_count": 3,
     *         "child_count": 2,
     *         "pv_left": 1400,
     *         "pv_left_format": "1 400.00 PV",
     *         "pv_right": 1400,
     *         "pv_right_format": "1 400.00 PV",
     *         "bv_left": 94500,
     *         "bv_left_format": "94 500.00 BV",
     *         "bv_right": 94500,
     *         "bv_right_format": "94 500.00 BV",
     *         "binary_for_bonus_volume_left": "0.00 BV",
     *         "binary_for_bonus_volume_right": "0.00 BV",
     *         "bonus_rank_sum": 0,
     *         "bonus_rank_sum_format": "0.00 RUB",
     *         "bonus_binary_sum": 4725,
     *         "bonus_binary_sum_format": "4 725.00 RUB",
     *         "bonus_referral_sum": 0,
     *         "bonus_referral_sum_format": "0.00 RUB",
     *         "bonus_referral_sum_level_1": 0,
     *         "bonus_referral_sum_level_1_format": "0.00 RUB",
     *         "bonus_referral_sum_level_2": 0,
     *         "bonus_referral_sum_level_2_format": "0.00 RUB",
     *         "bonus_leader_sum": 0,
     *         "bonus_leader_sum_format": "0.00 RUB",
     *         "all_bonus_sum": 4725,
     *         "all_bonus_sum_format": "4 725.00 RUB",
     *         "details": {
     *             "rank_list": [
     *                 "Консультант"
     *             ],
     *             "bonus_list": {
     *                 "referral": {
     *                     "title": "Реферальный Бонус",
     *                     "total": "0.00 RUB",
     *                     "total_by_level": {},
     *                     "list": []
     *                 },
     *                 "binary": {
     *                     "title": "Выплата по товарообороту ветки",
     *                     "total": "4 725.00 RUB",
     *                     "list": [
     *                         "Выплата по товарообороту ветки при постановке Пользователь 9: 54000 * 5% = 2 700.00 RUB",
     *                         "Выплата по товарообороту ветки при постановке Пользователь 13: 40500 * 5% = 2 025.00 RUB"
     *                     ]
     *                 },
     *                 "leader": {
     *                     "title": "Лидерский Бонус",
     *                     "total": "0.00 RUB",
     *                     "list": []
     *                 },
     *                 "rank": {
     *                     "title": "Квалификационный Бонус",
     *                     "total": "0.00 RUB",
     *                     "list": []
     *                 }
     *             }
     *         },
     *         "log": [
     *             [
     *                 "Добавлен персональный объем +200.00 PV и +13 500.00 BV."
     *             ],
     *             [
     *                 "Постановка пользователя “Пользователь 4” с контрактом Business в левую ветку.",
     *                 "Добавлено +200.00 PV и +13 500.00 BV в левую ветку."
     *             ],
     *             [
     *                 "Постановка пользователя “Пользователь 5” с контрактом Business в правую ветку.",
     *                 "Добавлено +200.00 PV и +13 500.00 BV в правую ветку."
     *             ],
     *             [
     *                 "Постановка пользователя “Пользователь 8” с контрактом Elite в левую ветку.",
     *                 "Добавлено +600.00 PV и +40 500.00 BV в левую ветку."
     *             ],
     *             [
     *                 "Постановка пользователя “Пользователь 9” с контрактом Elite в правую ветку.",
     *                 "Добавлено +600.00 PV и +40 500.00 BV в правую ветку.",
     *                 "Получен ранг “Консультант”!",
     *                 "Выплата по товарообороту ветки при постановке Пользователь 9: 54000 * 5% = 2 700.00 RUB"
     *             ],
     *             [
     *                 "Постановка пользователя “Пользователь 10” с контрактом Elite в правую ветку.",
     *                 "Добавлено +600.00 PV и +40 500.00 BV в правую ветку."
     *             ],
     *             [
     *                 "Постановка пользователя “Пользователь 13” с контрактом Elite в левую ветку.",
     *                 "Добавлено +600.00 PV и +40 500.00 BV в левую ветку.",
     *                 "Выплата по товарообороту ветки при постановке Пользователь 13: 40500 * 5% = 2 025.00 RUB"
     *             ]
     *         ]
     *     }
     * }
     * @param string $token
     * @param int $nodeId
     * @return StructureNodeDetails
     */
    public function getNodeDetails(string $token, int $nodeId): StructureNodeDetails
    {
        $structure = Structure::findByToken($token, Response::HTTP_NOT_FOUND);

        $calculator = new CalculatorService(config('app.currency_code'), $structure);
        $calculator->calculate();

        $node = $structure->getNodeById($structure->getRoot(), $nodeId);
        if (!$node) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return resolve(StructureNodeDetails::class, [
            'structure' => $structure,
            'resource' => $node,
        ]);
    }

    /**
     * Structure get last
     *
     * Получение данных структуры,
     * последней созданной авторизованным пользователем
     *
     * Пример ответа: см Structure add node
     *
     * @return StructureResource
     */
    public function structureGetLast(): StructureResource
    {
        $userToken = CalculatorAuth::token();
        $structure = Structure::where('calculator_user_id', $userToken->user->id)->latest()->first();
        return $this->structureCalculate($structure);
    }

    /**
     * Structure get all
     *
     * Получение всех структур созданных авторизованным пользователем.
     * Краткие данные - для перехода к ним.
     *
     * @response {
     *     "data": [
     *         {
     *             "id": 17,
     *             "created_at": "31.10.2024 08:48",
     *             "calculator_owner_email": "admin@gmail.com",
     *             "calculator_owner_id": 1,
     *             "can_edit": true,
     *             "token_view": "5019e96af29595c9885eeb0ad3fb182e861b7bdaa02ab08d9b23d98c2a027e38",
     *             "token_edit": "806b1f288196f34f4144f2d18d885d9eb7ed43de7f1e0bc4d520592b9662e04b",
     *             "last_added_node": null
     *         },
     *         {
     *             "id": 15,
     *             "created_at": "30.10.2024 08:10",
     *             "calculator_owner_email": "admin@gmail.com",
     *             "calculator_owner_id": 1,
     *             "can_edit": true,
     *             "token_view": "709711778bc54a630817416f3200d2fddb5571d66cd728b264200ed184aa5bdb",
     *             "token_edit": "aab56b88383bdecb09a9a1118eee9c34a340d54401e53954eea31257a166e5e7",
     *             "last_added_node": null
     *         }
     *     ]
     * }
     *
     * @return StructureLightCollection
     */
    public function structureGetAll(): StructureLightCollection
    {
        $userToken = CalculatorAuth::token();
        return resolve(StructureLightCollection::class, ['resource' => Structure::where('calculator_user_id', $userToken->user->id)
            ->orderBy('id', 'desc')
            ->where('max_node_id', '>', 1)
            ->get()]);
    }

    /**
     * @param Structure $structure
     * @return StructureResource
     */
    protected function structureCalculate(Structure $structure): StructureResource
    {
        $calculator = new CalculatorService(config('app.currency_code'), $structure);
        $calculator->calculate();
        $token = CalculatorAuth::token();
        $log = $calculator->getLog();
        return resolve(StructureResource::class, ['resource' => $structure])
            ->additional([
                    'auth_email' => $token?->user?->email,
                    'log' => resolve(LogItemCollection::class, ['resource' => $log->getEventList()]),
                    'notify' => resolve(NotifyItemCollection::class, ['resource' => $log->getNotifyList()])
                ]
            );
    }

}
