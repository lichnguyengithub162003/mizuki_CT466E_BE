<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\ListOrdersRequest;
use App\Http\Resources\Admin\OrderResource;
use App\Services\Admin\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class OrderController extends BaseController
{
    public function __construct(
        private readonly OrderService $orders,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/orders",
     *     operationId="adminListOrders",
     *     tags={"Admin Orders"},
     *     summary="Danh sách đơn hàng theo phạm vi chi nhánh",
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="branch_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="keyword", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", minimum=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", minimum=1, maximum=100)),
     *     @OA\Response(response=200, description="Danh sách đơn hàng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền")
     * )
     */
    public function index(ListOrdersRequest $request): JsonResponse
    {
        $paginator = $this->orders->paginate($request->user(), $request->validated());

        return $this->paginatedResponse(
            request: $request,
            resource: OrderResource::collection($paginator),
            paginator: $paginator,
            message: 'Lấy danh sách đơn hàng thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/orders/{id}",
     *     operationId="adminShowOrder",
     *     tags={"Admin Orders"},
     *     summary="Chi tiết đơn hàng",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Chi tiết đơn hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy đơn hàng")
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = $this->orders->detail($request->user(), $id);

        if ($order === null) {
            return $this->orderNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new OrderResource($order),
            message: 'Lấy chi tiết đơn hàng thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/orders/{id}/confirm",
     *     operationId="adminConfirmOrder",
     *     tags={"Admin Orders"},
     *     summary="Xác nhận đơn hàng đang chờ",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Đã xác nhận đơn hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy đơn hàng"),
     *     @OA\Response(response=422, description="Trạng thái không hợp lệ")
     * )
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $order = $this->orders->confirm($request->user(), $id);

        if ($order === null) {
            return $this->orderNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new OrderResource($order),
            message: 'Xác nhận đơn hàng thành công!',
        );
    }

    private function orderNotFound(): JsonResponse
    {
        return $this->errorResponse(
            message: 'Không tìm thấy đơn hàng',
            status: 404,
        );
    }
}
