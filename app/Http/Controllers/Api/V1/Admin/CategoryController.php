<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\CategoryMutationRequest;
use App\Http\Resources\Admin\CategoryResource;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal) {}

    public function index(AdminListRequest $request): JsonResponse
    {
        $items = $this->portal->categories($request->validated());
        return $this->paginatedResponse($request, CategoryResource::collection($items), $items, 'Lấy danh sách danh mục thành công!');
    }

    public function store(CategoryMutationRequest $request): JsonResponse
    {
        return $this->successResponse($request, new CategoryResource($this->portal->createCategory($request->validated())), 'Tạo danh mục thành công!', 201);
    }

    public function show(Request $request, int $category): JsonResponse
    {
        $item = $this->portal->category($category);
        return $item === null ? $this->errorResponse('Không tìm thấy danh mục', 404) : $this->successResponse($request, new CategoryResource($item), 'Lấy danh mục thành công!');
    }

    public function update(CategoryMutationRequest $request, int $category): JsonResponse
    {
        $item = $this->portal->updateCategory($category, $request->validated());
        return $item === null ? $this->errorResponse('Không tìm thấy danh mục', 404) : $this->successResponse($request, new CategoryResource($item), 'Cập nhật danh mục thành công!');
    }
}
