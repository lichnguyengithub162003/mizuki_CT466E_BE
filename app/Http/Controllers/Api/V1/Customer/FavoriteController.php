<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\StoreFavoriteRequest;
use App\Http\Resources\Customer\FavoriteResource;
use App\Services\FavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class FavoriteController extends BaseController
{
    public function __construct(
        private readonly FavoriteService $favorites,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/favorites",
     *     operationId="listCustomerFavorites",
     *     tags={"Customer Favorites"},
     *     summary="Danh sách sản phẩm yêu thích của khách hàng",
     *     @OA\Response(response=200, description="Danh sách yêu thích có phân trang"),
     *     @OA\Response(response=401, description="Chưa đăng nhập")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->favorites->getForUser($request->user());

        return $this->paginatedResponse(
            request: $request,
            resource: FavoriteResource::collection($paginator->getCollection()),
            paginator: $paginator,
            message: 'Lấy danh sách yêu thích thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/customer/favorites",
     *     operationId="storeCustomerFavorite",
     *     tags={"Customer Favorites"},
     *     summary="Thêm sản phẩm vào yêu thích",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"product_id"},
     *             @OA\Property(property="product_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Đã thêm sản phẩm vào yêu thích"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=409, description="Sản phẩm đã có trong yêu thích"),
     *     @OA\Response(response=422, description="Dữ liệu không hợp lệ")
     * )
     */
    public function store(StoreFavoriteRequest $request): JsonResponse
    {
        $favorite = $this->favorites->addForUser(
            user: $request->user(),
            productId: (int) $request->validated('product_id'),
        );

        if ($favorite === null) {
            return $this->errorResponse(
                message: 'Sản phẩm đã có trong danh sách yêu thích',
                status: 409,
            );
        }

        return $this->successResponse(
            request: $request,
            resource: new FavoriteResource($favorite),
            message: 'Đã thêm vào yêu thích!',
            status: 201,
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/customer/favorites/{product_id}",
     *     operationId="deleteCustomerFavorite",
     *     tags={"Customer Favorites"},
     *     summary="Bỏ sản phẩm khỏi yêu thích",
     *     @OA\Parameter(
     *         name="product_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Đã bỏ sản phẩm khỏi yêu thích"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=404, description="Sản phẩm chưa có trong yêu thích")
     * )
     */
    public function destroy(Request $request, int $productId): JsonResponse
    {
        if (! $this->favorites->removeForUser($request->user(), $productId)) {
            return $this->errorResponse(
                message: 'Không tìm thấy sản phẩm trong danh sách yêu thích',
                status: 404,
            );
        }

        return $this->successResponse(
            request: $request,
            resource: null,
            message: 'Đã bỏ yêu thích!',
        );
    }
}
