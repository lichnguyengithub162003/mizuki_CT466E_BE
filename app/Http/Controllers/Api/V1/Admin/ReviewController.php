<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\ReviewModerationRequest;
use App\Http\Resources\Admin\AdminReviewResource;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal) {}

    public function index(AdminListRequest $request): JsonResponse
    {
        $items = $this->portal->reviews($request->user(), $request->validated());
        return $this->paginatedResponse($request, AdminReviewResource::collection($items), $items, 'Lấy danh sách đánh giá thành công!');
    }

    public function show(Request $request, int $review): JsonResponse
    {
        $item = $this->portal->review($request->user(), $review);
        return $item === null ? $this->errorResponse('Không tìm thấy đánh giá', 404) : $this->successResponse($request, new AdminReviewResource($item), 'Lấy đánh giá thành công!');
    }

    public function update(ReviewModerationRequest $request, int $review): JsonResponse
    {
        $item = $this->portal->moderateReview($request->user(), $review, $request->validated());
        return $item === null ? $this->errorResponse('Không tìm thấy đánh giá', 404) : $this->successResponse($request, new AdminReviewResource($item), 'Kiểm duyệt đánh giá thành công!');
    }
}
