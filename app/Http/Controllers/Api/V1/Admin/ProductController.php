<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\ProductMutationRequest;
use App\Http\Resources\Admin\ProductResource;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal)
    {
    }

    public function index(AdminListRequest $request): JsonResponse
    {
        $items = $this->portal->products($request->validated());

        return $this->paginatedResponse($request, ProductResource::collection($items), $items, 'Lấy danh sách sản phẩm thành công!');
    }

    public function store(ProductMutationRequest $request): JsonResponse
    {
        return $this->successResponse($request, new ProductResource($this->portal->createProduct($request->validated())), 'Tạo sản phẩm thành công!', 201);
    }

    public function show(Request $request, int $product): JsonResponse
    {
        $item = $this->portal->product($product);

        return $item === null ? $this->errorResponse('Không tìm thấy sản phẩm', 404)
            : $this->successResponse($request, new ProductResource($item), 'Lấy sản phẩm thành công!');
    }

    public function update(ProductMutationRequest $request, int $product): JsonResponse
    {
        $item = $this->portal->updateProduct($product, $request->validated());

        return $item === null ? $this->errorResponse('Không tìm thấy sản phẩm', 404)
            : $this->successResponse($request, new ProductResource($item), 'Cập nhật sản phẩm thành công!');
    }
}
