<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\CancelOrderRequest;
use App\Http\Requests\Customer\CreateOrderRequest;
use App\Http\Requests\Customer\IndexOrderRequest;
use App\Http\Requests\Customer\RequestRefundRequest;
use App\Http\Resources\Customer\OrderListResource;
use App\Http\Resources\Customer\OrderResource;
use App\Http\Resources\Customer\RefundResource;
use App\Services\OrderService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class OrderController extends BaseController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly RefundService $refunds,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/v1/customer/orders",
     *     operationId="customerCheckout",
     *     tags={"Customer Orders"},
     *     summary="Tạo đơn hàng từ giỏ hàng hiện tại",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"delivery_method", "payment_method"},
     *
     *         @OA\Property(property="delivery_method", type="string", enum={"pickup", "delivery"}),
     *         @OA\Property(property="address_id", type="integer", nullable=true),
     *         @OA\Property(property="shipping_quote_token", type="string", nullable=true, description="Bắt buộc khi delivery"),
     *         @OA\Property(property="payment_method", type="string", enum={"wallet", "vnpay", "cash"})
     *     )),
     *
     *     @OA\Response(response=201, description="Đã tạo đơn hàng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=422, description="Giỏ hàng không đủ điều kiện checkout")
     * )
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orders->checkout($request->user(), $request->validated());

        return $this->successResponse(
            request: $request,
            resource: new OrderResource($order),
            message: 'Đặt hàng thành công!',
            status: 201,
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/orders",
     *     operationId="customerOrderList",
     *     tags={"Customer Orders"},
     *     summary="Danh sách đơn hàng của khách hàng",
     *
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Danh sách đơn hàng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập")
     * )
     */
    public function index(IndexOrderRequest $request): JsonResponse
    {
        $paginator = $this->orders->paginate($request->user(), $request->validated());

        return $this->paginatedResponse(
            request: $request,
            resource: OrderListResource::collection($paginator),
            paginator: $paginator,
            message: 'Lấy danh sách đơn hàng thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/orders/{id}",
     *     operationId="customerOrderDetail",
     *     tags={"Customer Orders"},
     *     summary="Chi tiết đơn hàng của khách hàng",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Chi tiết đơn hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy đơn hàng")
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = $this->orders->detail($request->user(), $id);

        if ($order === null) {
            return $this->errorResponse('Không tìm thấy đơn hàng', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new OrderResource($order),
            message: 'Lấy chi tiết đơn hàng thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/customer/orders/{id}/cancel",
     *     operationId="customerCancelOrder",
     *     tags={"Customer Orders"},
     *     summary="Hủy đơn hàng",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"reason_type"},
     *
     *         @OA\Property(property="reason_type", type="string"),
     *         @OA\Property(property="reason", type="string", nullable=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Đã hủy đơn hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy đơn hàng"),
     *     @OA\Response(response=422, description="Không thể hủy ở trạng thái hiện tại")
     * )
     */
    public function cancel(CancelOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->orders->cancel($request->user(), $id, $request->validated());

        if ($order === null) {
            return $this->errorResponse('Không tìm thấy đơn hàng', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new OrderResource($order),
            message: 'Hủy đơn hàng thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/customer/orders/{id}/refund",
     *     operationId="customerRequestRefund",
     *     tags={"Customer Orders"},
     *     summary="Gửi yêu cầu hoàn tiền",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\MediaType(
     *         mediaType="multipart/form-data",
     *
     *         @OA\Schema(
     *             required={"reason_type", "evidence[]"},
     *
     *             @OA\Property(property="reason_type", type="string"),
     *             @OA\Property(property="reason", type="string", nullable=true),
     *             @OA\Property(property="evidence[]", type="array", @OA\Items(type="string", format="binary"))
     *         )
     *     )),
     *
     *     @OA\Response(response=201, description="Đã gửi yêu cầu hoàn tiền"),
     *     @OA\Response(response=404, description="Không tìm thấy đơn hàng"),
     *     @OA\Response(response=422, description="Dữ liệu hoặc trạng thái đơn không hợp lệ")
     * )
     */
    public function requestRefund(RequestRefundRequest $request, int $id): JsonResponse
    {
        $refund = $this->refunds->request(
            user: $request->user(),
            orderId: $id,
            data: $request->safe()->except('evidence'),
            evidence: $request->file('evidence', []),
        );

        if ($refund === null) {
            return $this->errorResponse('Không tìm thấy đơn hàng', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Đã gửi yêu cầu hoàn tiền!',
            status: 201,
        );
    }
}
