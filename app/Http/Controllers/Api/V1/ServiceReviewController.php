<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Clinic\ServiceReviewIndexRequest;
use App\Http\Resources\Clinic\ServiceReviewPageResource;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class ServiceReviewController extends BaseController
{
    public function __construct(
        private readonly ReviewService $reviews,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/services/{service}/reviews",
     *     operationId="listPublicServiceReviews",
     *     tags={"Clinics"},
     *     summary="Danh sách đánh giá đã hiển thị của dịch vụ",
     *
     *     @OA\Parameter(name="service", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="rating", in="query", @OA\Schema(type="integer", minimum=1, maximum=5)),
     *     @OA\Parameter(name="verified_service", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", minimum=1, maximum=50)),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", minimum=1)),
     *
     *     @OA\Response(response=200, description="Lấy đánh giá dịch vụ thành công"),
     *     @OA\Response(response=404, description="Không tìm thấy dịch vụ"),
     *     @OA\Response(response=422, description="Bộ lọc không hợp lệ")
     * )
     */
    public function index(ServiceReviewIndexRequest $request, string $service): JsonResponse
    {
        $result = $this->reviews->getActiveServiceReviews($service, $request->validated());

        if ($result === null) {
            return $this->errorResponse('Không tìm thấy dịch vụ', 404);
        }

        $paginator = $result['reviews'];

        return $this->successResponse(
            request: $request,
            resource: new ServiceReviewPageResource([
                'service' => $result['service'],
                'summary' => $result['summary'],
                'reviews' => $paginator->getCollection(),
            ]),
            message: 'Lấy đánh giá dịch vụ thành công!',
            meta: [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }
}
