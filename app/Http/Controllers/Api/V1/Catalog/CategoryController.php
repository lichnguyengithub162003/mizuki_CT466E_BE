<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\Catalog\CategoryResource;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class CategoryController extends BaseController
{
    public function __construct(
        private readonly CategoryService $categories,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/categories",
     *     operationId="listCategories",
     *     tags={"Catalog"},
     *     summary="Danh sách danh mục đang hiển thị theo cấu trúc phân cấp",
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách danh mục",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", example="Lấy danh sách danh mục thành công!"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: CategoryResource::collection($this->categories->getActiveHierarchy()),
            message: 'Lấy danh sách danh mục thành công!',
        );
    }
}
