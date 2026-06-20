<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Structure\Structure;
use Symfony\Component\HttpFoundation\Response;

class StructureEditTokenMiddleware
{
    /**
     * См. \Modules\Calculator\Models\Structure canEdit
     *
     * @param Request $request
     * @param Closure $next
     * @param $parameter
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $parameter)
    {
        $structureToken = $request->route($parameter);
        $structure = Structure::findByToken($structureToken, \Illuminate\Http\Response::HTTP_NOT_FOUND);

        $userToken = CalculatorAuth::token();
        if (!$userToken || !$structure || !$structure->canEdit($userToken)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
