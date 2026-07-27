<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\Catalog\BrandResource;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class BrandController extends BaseController
{
    public function __construct(
        private readonly BrandService $brands,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/brands",
     *     operationId="listBrands",
     *     tags={"Catalog"},
     *     summary="Danh sách thương hiệu đang hiển thị",
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách thương hiệu",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", example="Lấy danh sách thương hiệu thành công!"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: BrandResource::collection($this->brands->getActiveBrands()),
            message: 'Lấy danh sách thương hiệu thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/brands/{slug}",
     *     operationId="showBrand",
     *     tags={"Catalog"},
     *     summary="Chi tiết gian hàng thương hiệu",
     *     @OA\Parameter(
     *         name="slug",
     *         in="path",
     *         required=true,
     *         description="Slug của thương hiệu",
     *         @OA\Schema(type="string", example="mizuki")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Chi tiết thương hiệu",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object"),
     *             @OA\Property(property="message", type="string", example="Lấy thông tin thương hiệu thành công!"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy thương hiệu")
     * )
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: new BrandResource($this->brands->getActiveBrand($slug)),
            message: 'Lấy thông tin thương hiệu thành công!',
        );
    }
}
