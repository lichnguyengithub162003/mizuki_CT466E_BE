<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\DashboardRequest;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;

class DashboardController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal)
    {
    }

    public function __invoke(DashboardRequest $request): JsonResponse
    {
        return $this->successResponseRaw($request, $this->portal->dashboard($request->user(), $request->validated()), 'Lấy dashboard thành công!');
    }
}
