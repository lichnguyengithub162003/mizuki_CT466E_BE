<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\BrandMutationRequest;
use App\Http\Resources\Admin\BrandResource;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal) {}

    public function index(AdminListRequest $request): JsonResponse
    {
        $items = $this->portal->brands($request->validated());
        return $this->paginatedResponse($request, BrandResource::collection($items), $items, 'Lấy danh sách thương hiệu thành công!');
    }

    public function store(BrandMutationRequest $request): JsonResponse
    {
        return $this->successResponse($request, new BrandResource($this->portal->createBrand($request->validated())), 'Tạo thương hiệu thành công!', 201);
    }

    public function show(Request $request, int $brand): JsonResponse
    {
        $item = $this->portal->brand($brand);
        return $item === null ? $this->errorResponse('Không tìm thấy thương hiệu', 404) : $this->successResponse($request, new BrandResource($item), 'Lấy thương hiệu thành công!');
    }

    public function update(BrandMutationRequest $request, int $brand): JsonResponse
    {
        $item = $this->portal->updateBrand($brand, $request->validated());
        return $item === null ? $this->errorResponse('Không tìm thấy thương hiệu', 404) : $this->successResponse($request, new BrandResource($item), 'Cập nhật thương hiệu thành công!');
    }
}
