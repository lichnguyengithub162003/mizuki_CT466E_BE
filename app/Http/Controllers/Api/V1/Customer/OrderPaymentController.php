<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\PaymentResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class OrderPaymentController extends BaseController
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/orders/{id}/payment",
     *     operationId="customerShowOrderPayment",
     *     tags={"Customer Orders"},
     *     summary="Xem trạng thái thanh toán của đơn hàng",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Thông tin thanh toán"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải khách hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy thanh toán")
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $payment = $this->payments->forCustomer($request->user(), $id);

        if ($payment === null) {
            return $this->errorResponse(
                message: 'Không tìm thấy thông tin thanh toán',
                status: 404,
            );
        }

        return $this->successResponse(
            request: $request,
            resource: new PaymentResource($payment),
            message: 'Lấy thông tin thanh toán thành công!',
        );
    }
}
