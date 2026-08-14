<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\QuoteShippingRequest;
use App\Http\Resources\Customer\ShippingQuoteResource;
use App\Services\Shipping\GhnWebhookService;
use App\Services\Shipping\ShippingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;

class ShippingController extends BaseController
{
    public function __construct(
        private readonly ShippingQuoteService $quotes,
        private readonly GhnWebhookService $webhooks,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/v1/customer/shipping/quote",
     *     operationId="customerShippingQuote",
     *     tags={"Customer Shipping"},
     *     summary="Lấy báo giá vận chuyển GHN cho giỏ hàng hiện tại",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"address_id"},
     *
     *         @OA\Property(property="address_id", type="integer")
     *     )),
     *
     *     @OA\Response(response=200, description="Báo giá vận chuyển thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Sai vai trò"),
     *     @OA\Response(response=422, description="Không thể tính phí vận chuyển")
     * )
     */
    public function quote(QuoteShippingRequest $request): JsonResponse
    {
        $quote = $this->quotes->quote(
            $request->user(),
            (int) $request->validated('address_id'),
        );

        return $this->successResponse(
            request: $request,
            resource: new ShippingQuoteResource($quote),
            message: 'Lấy phí vận chuyển thành công!',
        );
    }

    public function webhook(Request $request): JsonResponse
    {
        $result = $this->webhooks->handle($request->all());

        if ($result === null) {
            return $this->errorResponse(
                message: 'Không tìm thấy vận đơn GHN',
                status: 404,
            );
        }

        $shipment = $result['shipment'];

        return $this->successResponse(
            request: $request,
            resource: new JsonResource([
                'id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'ghn_order_code' => $shipment->ghn_order_code,
                'status' => $shipment->status,
                'changed' => $result['changed'],
            ]),
            message: 'Đã tiếp nhận trạng thái vận đơn GHN!',
        );
    }
}
