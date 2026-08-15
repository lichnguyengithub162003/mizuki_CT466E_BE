<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\StoreReviewRequest;
use App\Http\Requests\Customer\UpdateReviewRequest;
use App\Http\Resources\Customer\ReviewResource;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class ReviewController extends BaseController
{
    public function __construct(
        private readonly ReviewService $reviews,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/v1/customer/reviews",
     *     operationId="storeCustomerProductReview",
     *     tags={"Customer Reviews"},
     *     summary="Đánh giá sản phẩm đã mua",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"order_item_id", "rating"},
     *
     *         @OA\Property(property="order_item_id", type="integer"),
     *         @OA\Property(property="rating", type="integer", minimum=1, maximum=5),
     *         @OA\Property(property="title", type="string", nullable=true),
     *         @OA\Property(property="comment", type="string", nullable=true)
     *     )),
     *
     *     @OA\Response(response=201, description="Đánh giá sản phẩm thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền"),
     *     @OA\Response(response=404, description="Không tìm thấy sản phẩm đã mua"),
     *     @OA\Response(response=422, description="Không đủ điều kiện đánh giá")
     * )
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        $review = $this->reviews->create($request->user(), $request->validated());

        if ($review === null) {
            return $this->errorResponse('Không tìm thấy sản phẩm đã mua', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new ReviewResource($review),
            message: 'Đánh giá sản phẩm thành công!',
            status: 201,
        );
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/customer/reviews/{review}",
     *     operationId="updateCustomerProductReview",
     *     tags={"Customer Reviews"},
     *     summary="Cập nhật đánh giá sản phẩm của khách hàng",
     *
     *     @OA\Parameter(name="review", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *
     *         @OA\Property(property="rating", type="integer", minimum=1, maximum=5),
     *         @OA\Property(property="title", type="string", nullable=true),
     *         @OA\Property(property="comment", type="string", nullable=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Cập nhật đánh giá thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền"),
     *     @OA\Response(response=404, description="Không tìm thấy đánh giá"),
     *     @OA\Response(response=422, description="Dữ liệu không hợp lệ")
     * )
     */
    public function update(UpdateReviewRequest $request, int $review): JsonResponse
    {
        $updated = $this->reviews->update($request->user(), $review, $request->validated());

        if ($updated === null) {
            return $this->errorResponse('Không tìm thấy đánh giá', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new ReviewResource($updated),
            message: 'Cập nhật đánh giá thành công!',
        );
    }
}
