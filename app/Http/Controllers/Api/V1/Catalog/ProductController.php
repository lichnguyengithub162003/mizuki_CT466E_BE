<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Catalog\ProductIndexRequest;
use App\Http\Requests\Catalog\ProductSearchRequest;
use App\Http\Resources\Catalog\ProductListResource;
use App\Http\Resources\Catalog\ProductDetailResource;
use App\Http\Resources\Catalog\ProductSuggestResource;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class ProductController extends BaseController
{
    public function __construct(
        private readonly ProductService $products,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/products",
     *     operationId="listProducts",
     *     tags={"Catalog"},
     *     summary="Danh sách sản phẩm đang hiển thị",
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", minimum=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20, maximum=100)),
     *     @OA\Parameter(name="category_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="brand_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="price_min", in="query", @OA\Schema(type="integer", minimum=0)),
     *     @OA\Parameter(name="price_max", in="query", @OA\Schema(type="integer", minimum=0)),
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         @OA\Schema(type="string", enum={"newest", "best_selling", "price_asc", "price_desc"}, default="newest")
     *     ),
     *     @OA\Parameter(name="keyword", in="query", @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách sản phẩm có phân trang",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", example="Lấy danh sách sản phẩm thành công!"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Query params không hợp lệ")
     * )
     */
    public function index(ProductIndexRequest $request): JsonResponse
    {
        $paginator = $this->products->getActiveProducts($request->validated());

        return $this->paginatedResponse(
            request: $request,
            resource: ProductListResource::collection($paginator->getCollection()),
            paginator: $paginator,
            message: 'Lấy danh sách sản phẩm thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/products/search",
     *     operationId="searchProductSuggestions",
     *     tags={"Catalog"},
     *     summary="Gợi ý sản phẩm theo từ khóa",
     *     @OA\Parameter(
     *         name="keyword",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string", minLength=1, example="serum")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         @OA\Schema(type="integer", minimum=1, maximum=20, default=8)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách gợi ý sản phẩm",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", example="Tìm kiếm thành công!"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Query params không hợp lệ")
     * )
     */
    public function search(ProductSearchRequest $request): JsonResponse
    {
        $products = $this->products->searchActiveProducts(
            keyword: (string) $request->validated('keyword'),
            limit: (int) $request->validated('limit', 8),
        );

        return $this->successResponse(
            request: $request,
            resource: ProductSuggestResource::collection($products),
            message: 'Tìm kiếm thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/products/{slug}",
     *     operationId="showProduct",
     *     tags={"Catalog"},
     *     summary="Chi tiết sản phẩm",
     *     @OA\Parameter(
     *         name="slug",
     *         in="path",
     *         required=true,
     *         description="Slug của sản phẩm",
     *         @OA\Schema(type="string", example="serum-phuc-hoi-da")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Chi tiết sản phẩm, biến thể và tồn kho",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object"),
     *             @OA\Property(property="message", type="string", example="Lấy chi tiết sản phẩm thành công!"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Không tìm thấy sản phẩm")
     * )
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $product = $this->products->getActiveProductDetail($slug);

        if ($product === null) {
            return $this->errorResponse(
                message: 'Không tìm thấy sản phẩm',
                status: 404,
            );
        }

        return $this->successResponse(
            request: $request,
            resource: new ProductDetailResource($product),
            message: 'Lấy chi tiết sản phẩm thành công!',
        );
    }
}
