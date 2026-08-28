<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Resources\Admin\CustomerResource;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal)
    {
    }

    public function index(AdminListRequest $request): JsonResponse
    {
        $items = $this->portal->customers($request->user(), $request->validated());

        return $this->paginatedResponse($request, CustomerResource::collection($items), $items, 'Lấy danh sách khách hàng thành công!');
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        $item = $this->portal->customer($request->user(), $customer);

        return $item === null ? $this->errorResponse('Không tìm thấy khách hàng', 404)
            : $this->successResponse($request, new CustomerResource($item), 'Lấy thông tin khách hàng thành công!');
    }
}
