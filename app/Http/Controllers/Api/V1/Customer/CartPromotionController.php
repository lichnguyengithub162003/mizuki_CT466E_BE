<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\ApplyPromotionRequest;
use App\Http\Resources\Customer\AvailablePromotionResource;
use App\Http\Resources\Customer\CartResource;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class CartPromotionController extends BaseController
{
    public function __construct(
        private readonly PromotionService $promotions,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/cart/promotions",
     *     operationId="listAvailableCartPromotions",
     *     tags={"Customer Cart"},
     *     summary="Xem voucher khả dụng cho giỏ hàng",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Danh sách voucher khả dụng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải tài khoản khách hàng")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->promotions->getAvailableForUser($request->user());

        return $this->successResponse(
            request: $request,
            resource: AvailablePromotionResource::collection($result['promotions']),
            message: $result['message'],
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/customer/cart/promotion",
     *     operationId="applyCartPromotion",
     *     tags={"Customer Cart"},
     *     summary="Áp dụng voucher cho giỏ hàng",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"code"},
     *             @OA\Property(property="code", type="string", example="MIZUKI10")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Đã áp dụng voucher"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=422, description="Voucher không đủ điều kiện áp dụng")
     * )
     */
    public function store(ApplyPromotionRequest $request): JsonResponse
    {
        $cart = $this->promotions->applyForUser(
            user: $request->user(),
            code: $request->validated('code'),
        );

        return $this->successResponse(
            request: $request,
            resource: new CartResource($cart),
            message: 'Đã áp dụng voucher!',
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/customer/cart/promotion",
     *     operationId="removeCartPromotion",
     *     tags={"Customer Cart"},
     *     summary="Hủy voucher đã áp dụng trên giỏ hàng",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Đã hủy voucher"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=404, description="Giỏ hàng chưa áp dụng voucher")
     * )
     */
    public function destroy(Request $request): JsonResponse
    {
        $cart = $this->promotions->removeForUser($request->user());

        if ($cart === null) {
            return $this->errorResponse(
                message: 'Giỏ hàng chưa áp dụng voucher nào',
                status: 404,
            );
        }

        return $this->successResponse(
            request: $request,
            resource: new CartResource($cart),
            message: 'Đã hủy voucher!',
        );
    }
}
