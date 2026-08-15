<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\StoreServiceReviewRequest;
use App\Http\Requests\Customer\UpdateServiceReviewRequest;
use App\Http\Resources\Customer\ServiceReviewResource;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class ServiceReviewController extends BaseController
{
    public function __construct(
        private readonly ReviewService $reviews,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/v1/customer/service-reviews",
     *     operationId="storeCustomerServiceReview",
     *     tags={"Customer Reviews"},
     *     summary="Đánh giá dịch vụ từ lịch hẹn đã hoàn thành",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"appointment_id", "rating"},
     *
     *         @OA\Property(property="appointment_id", type="integer"),
     *         @OA\Property(property="rating", type="integer", minimum=1, maximum=5),
     *         @OA\Property(property="title", type="string", nullable=true),
     *         @OA\Property(property="comment", type="string", nullable=true)
     *     )),
     *
     *     @OA\Response(response=201, description="Đánh giá dịch vụ thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền"),
     *     @OA\Response(response=404, description="Không tìm thấy lịch hẹn"),
     *     @OA\Response(response=422, description="Lịch hẹn chưa đủ điều kiện hoặc dữ liệu không hợp lệ")
     * )
     */
    public function store(StoreServiceReviewRequest $request): JsonResponse
    {
        $review = $this->reviews->createServiceReview($request->user(), $request->validated());

        if ($review === null) {
            return $this->errorResponse('Không tìm thấy lịch hẹn đã hoàn thành', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new ServiceReviewResource($review),
            message: 'Đánh giá dịch vụ thành công!',
            status: 201,
        );
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/customer/service-reviews/{review}",
     *     operationId="updateCustomerServiceReview",
     *     tags={"Customer Reviews"},
     *     summary="Cập nhật đánh giá dịch vụ của khách hàng",
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
     *     @OA\Response(response=200, description="Cập nhật đánh giá dịch vụ thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền"),
     *     @OA\Response(response=404, description="Không tìm thấy đánh giá"),
     *     @OA\Response(response=422, description="Đánh giá không thuộc dịch vụ hoặc dữ liệu không hợp lệ")
     * )
     */
    public function update(UpdateServiceReviewRequest $request, int $review): JsonResponse
    {
        $updated = $this->reviews->updateServiceReview(
            $request->user(),
            $review,
            $request->validated(),
        );

        if ($updated === null) {
            return $this->errorResponse('Không tìm thấy đánh giá', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new ServiceReviewResource($updated),
            message: 'Cập nhật đánh giá dịch vụ thành công!',
        );
    }
}
