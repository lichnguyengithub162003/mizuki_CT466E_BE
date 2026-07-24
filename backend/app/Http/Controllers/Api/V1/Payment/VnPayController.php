<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\Payment\VnPayReturnResource;
use App\Http\Resources\Payment\VnPayUrlResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class VnPayController extends BaseController
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/v1/customer/orders/{id}/payment/vnpay",
     *     operationId="customerCreateVnPayUrl",
     *     tags={"Customer Payments"},
     *     summary="Tạo URL thanh toán VNPay Sandbox",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Tạo URL thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải khách hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy đơn hàng"),
     *     @OA\Response(response=422, description="Đơn hàng không thể thanh toán qua VNPay")
     * )
     */
    public function create(Request $request, int $id): JsonResponse
    {
        $result = $this->payments->createVnPayUrl(
            $request->user(),
            $id,
            (string) $request->ip(),
        );

        if ($result === null) {
            return $this->errorResponse('Không tìm thấy đơn hàng', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new VnPayUrlResource($result),
            message: 'Tạo URL thanh toán VNPay thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/payments/vnpay/return",
     *     operationId="vnpayReturn",
     *     tags={"Payments"},
     *     summary="Xác minh kết quả VNPay trả về cho frontend",
     *
     *     @OA\Response(response=200, description="Callback hợp lệ"),
     *     @OA\Response(response=400, description="Callback không hợp lệ")
     * )
     */
    public function handleReturn(Request $request): JsonResponse
    {
        $result = $this->payments->handleVnPayReturn($request->query());

        if (! $result['valid']) {
            return $this->errorResponse((string) $result['reason'], 400);
        }

        $status = $result['data']['status'];

        return $this->successResponse(
            request: $request,
            resource: new VnPayReturnResource($result['data']),
            message: $status === 'paid'
                ? 'Thanh toán thành công!'
                : 'Giao dịch chưa được thanh toán thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/payments/vnpay/ipn",
     *     operationId="vnpayIpn",
     *     tags={"Payments"},
     *     summary="Tiếp nhận IPN từ VNPay",
     *
     *     @OA\Response(response=200, description="Phản hồi theo contract VNPay")
     * )
     */
    public function ipn(Request $request): JsonResponse
    {
        return response()->json(
            $this->payments->handleVnPayIpn($request->query()),
        );
    }
}
