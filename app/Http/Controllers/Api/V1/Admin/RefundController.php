<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\ApproveRefundRequest;
use App\Http\Requests\Admin\ListRefundsRequest;
use App\Http\Requests\Admin\RejectRefundRequest;
use App\Http\Resources\Admin\RefundResource;
use App\Services\Admin\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class RefundController extends BaseController
{
    public function __construct(
        private readonly RefundService $refunds,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/admin/refunds",
     *     operationId="adminListRefunds",
     *     tags={"Admin Refunds"},
     *     summary="Danh sách yêu cầu hoàn tiền",
     *
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"requested", "approved", "rejected", "refunded"})),
     *     @OA\Parameter(name="return_status", in="query", @OA\Schema(type="string", enum={"not_required", "awaiting_return", "in_transit", "received", "restocked", "not_restockable"})),
     *     @OA\Parameter(name="branch_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="keyword", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", minimum=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", minimum=1, maximum=100)),
     *
     *     @OA\Response(response=200, description="Danh sách yêu cầu hoàn tiền"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền")
     * )
     */
    public function index(ListRefundsRequest $request): JsonResponse
    {
        $paginator = $this->refunds->paginate($request->user(), $request->validated());

        return $this->paginatedResponse(
            request: $request,
            resource: RefundResource::collection($paginator),
            paginator: $paginator,
            message: 'Lấy danh sách yêu cầu hoàn tiền thành công!',
        );
    }

    public function counts(Request $request): JsonResponse
    {
        return $this->successResponseRaw(
            request: $request,
            data: $this->refunds->counts($request->user()),
            message: 'Lấy số lượng yêu cầu hoàn tiền cần xử lý thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/refunds/{id}",
     *     operationId="adminShowRefund",
     *     tags={"Admin Refunds"},
     *     summary="Chi tiết yêu cầu hoàn tiền",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Chi tiết yêu cầu hoàn tiền"),
     *     @OA\Response(response=404, description="Không tìm thấy yêu cầu hoàn tiền")
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $refund = $this->refunds->detail($request->user(), $id);

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Lấy chi tiết yêu cầu hoàn tiền thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/refunds/{id}/approve",
     *     operationId="adminApproveRefund",
     *     tags={"Admin Refunds"},
     *     summary="Duyệt yêu cầu hoàn tiền",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(@OA\JsonContent(
     *
     *         @OA\Property(property="approved_amount", type="integer", minimum=1),
     *         @OA\Property(property="review_note", type="string", nullable=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Đã duyệt yêu cầu hoàn tiền"),
     *     @OA\Response(response=404, description="Không tìm thấy yêu cầu hoàn tiền"),
     *     @OA\Response(response=422, description="Yêu cầu đã xử lý hoặc số tiền không hợp lệ")
     * )
     */
    public function approve(ApproveRefundRequest $request, int $id): JsonResponse
    {
        $refund = $this->refunds->approve($request->user(), $id, $request->validated());

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Duyệt yêu cầu hoàn tiền thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/refunds/{id}/reject",
     *     operationId="adminRejectRefund",
     *     tags={"Admin Refunds"},
     *     summary="Từ chối yêu cầu hoàn tiền",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"review_note"},
     *
     *         @OA\Property(property="review_note", type="string")
     *     )),
     *
     *     @OA\Response(response=200, description="Đã từ chối yêu cầu hoàn tiền"),
     *     @OA\Response(response=404, description="Không tìm thấy yêu cầu hoàn tiền"),
     *     @OA\Response(response=422, description="Yêu cầu đã xử lý")
     * )
     */
    public function reject(RejectRefundRequest $request, int $id): JsonResponse
    {
        $refund = $this->refunds->reject($request->user(), $id, $request->validated());

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Từ chối yêu cầu hoàn tiền thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/refunds/{id}/wallet-payout",
     *     operationId="adminRefundWalletPayout",
     *     tags={"Admin Refunds"},
     *     summary="Chi trả khoản hoàn tiền vào ví Mizuki",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Chi trả vào ví thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền"),
     *     @OA\Response(response=404, description="Không tìm thấy yêu cầu hoàn tiền"),
     *     @OA\Response(response=422, description="Yêu cầu hoàn tiền chưa đủ điều kiện chi trả")
     * )
     */
    public function walletPayout(Request $request, int $id): JsonResponse
    {
        $refund = $this->refunds->payoutToWallet($request->user(), $id);

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Chi trả hoàn tiền vào ví thành công!',
        );
    }

    public function manualSettlement(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'settlement_reference' => ['required', 'string', 'max:120'],
        ]);
        $refund = $this->refunds->completeManualSettlement(
            $request->user(),
            $id,
            (string) $data['settlement_reference'],
        );

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Đã ghi nhận hoàn tiền ngoài hệ thống!',
        );
    }

    public function receiveReturn(Request $request, int $id): JsonResponse
    {
        $refund = $this->refunds->receiveReturn($request->user(), $id);

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Đã ghi nhận nhận hàng hoàn!',
        );
    }

    public function restock(Request $request, int $id): JsonResponse
    {
        $refund = $this->refunds->restock($request->user(), $id);

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Đã nhập hàng hoàn về kho!',
        );
    }

    public function markNotRestockable(Request $request, int $id): JsonResponse
    {
        $refund = $this->refunds->markNotRestockable($request->user(), $id);

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Đã ghi nhận hàng hoàn không thể nhập lại kho!',
        );
    }

    public function rejectReturnInspection(Request $request, int $id): JsonResponse
    {
        $refund = $this->refunds->rejectReturnInspection($request->user(), $id);

        if ($refund === null) {
            return $this->refundNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new RefundResource($refund),
            message: 'Đã ghi nhận hàng hoàn không đạt điều kiện hoàn tiền!',
        );
    }

    private function refundNotFound(): JsonResponse
    {
        return $this->errorResponse(
            message: 'Không tìm thấy yêu cầu hoàn tiền',
            status: 404,
        );
    }
}
