<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\IndexPromotionRequest;
use App\Http\Requests\Admin\StorePromotionRequest;
use App\Http\Requests\Admin\UpdatePromotionRequest;
use App\Http\Resources\Admin\PromotionResource;
use App\Http\Resources\Admin\PromotionUsageStatsResource;
use App\Services\Admin\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class PromotionController extends BaseController
{
    public function __construct(
        private readonly PromotionService $promotions,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/promotions",
     *     operationId="adminListPromotions",
     *     tags={"Admin Promotions"},
     *     summary="Danh sách promotion theo quyền quản trị",
     *     @OA\Parameter(name="is_active", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="discount_type", in="query", @OA\Schema(type="string", enum={"percentage", "fixed_amount"})),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", minimum=1, maximum=100)),
     *     @OA\Response(response=200, description="Danh sách promotion"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền quản trị promotion")
     * )
     */
    public function index(IndexPromotionRequest $request): JsonResponse
    {
        $paginator = $this->promotions->paginate($request->user(), $request->validated());

        return $this->paginatedResponse(
            request: $request,
            resource: PromotionResource::collection($paginator),
            paginator: $paginator,
            message: 'Lấy danh sách promotion thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/promotions",
     *     operationId="adminCreatePromotion",
     *     tags={"Admin Promotions"},
     *     summary="Tạo promotion",
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *     @OA\Response(response=201, description="Đã tạo promotion"),
     *     @OA\Response(response=403, description="Không có quyền tạo promotion"),
     *     @OA\Response(response=422, description="Dữ liệu không hợp lệ")
     * )
     */
    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = $this->promotions->create($request->user(), $request->validated());

        return $this->successResponse(
            request: $request,
            resource: new PromotionResource($promotion),
            message: 'Tạo promotion thành công!',
            status: 201,
        );
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/admin/promotions/{id}",
     *     operationId="adminUpdatePromotion",
     *     tags={"Admin Promotions"},
     *     summary="Cập nhật promotion",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *     @OA\Response(response=200, description="Đã cập nhật promotion"),
     *     @OA\Response(response=403, description="Không có quyền sửa promotion"),
     *     @OA\Response(response=404, description="Không tìm thấy promotion")
     * )
     */
    public function update(UpdatePromotionRequest $request, int $id): JsonResponse
    {
        $promotion = $this->promotions->update($request->user(), $id, $request->validated());

        if ($promotion === null) {
            return $this->promotionNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new PromotionResource($promotion),
            message: 'Cập nhật promotion thành công!',
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/admin/promotions/{id}",
     *     operationId="adminDeletePromotion",
     *     tags={"Admin Promotions"},
     *     summary="Xóa promotion",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Đã xóa promotion"),
     *     @OA\Response(response=403, description="Không có quyền xóa promotion"),
     *     @OA\Response(response=404, description="Không tìm thấy promotion")
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $this->promotions->delete($request->user(), $id);

        if ($deleted === null) {
            return $this->promotionNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: null,
            message: 'Xóa promotion thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/promotions/{id}/usage-stats",
     *     operationId="adminPromotionUsageStats",
     *     tags={"Admin Promotions"},
     *     summary="Thống kê lượt sử dụng promotion",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Thống kê lượt sử dụng"),
     *     @OA\Response(response=403, description="Không có quyền xem promotion"),
     *     @OA\Response(response=404, description="Không tìm thấy promotion")
     * )
     */
    public function usageStats(Request $request, int $id): JsonResponse
    {
        $promotion = $this->promotions->usageStats($request->user(), $id);

        if ($promotion === null) {
            return $this->promotionNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new PromotionUsageStatsResource($promotion),
            message: 'Lấy thống kê sử dụng promotion thành công!',
        );
    }

    private function promotionNotFound(): JsonResponse
    {
        return $this->errorResponse(
            message: 'Không tìm thấy promotion',
            status: 404,
        );
    }
}
