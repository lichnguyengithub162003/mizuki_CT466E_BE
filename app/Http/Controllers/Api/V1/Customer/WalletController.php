<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\ShowWalletRequest;
use App\Http\Requests\Customer\WalletTransactionIndexRequest;
use App\Http\Resources\Customer\WalletResource;
use App\Http\Resources\Customer\WalletTransactionResource;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class WalletController extends BaseController
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/customer/wallet",
     *     operationId="customerShowWallet",
     *     tags={"Customer Wallet"},
     *     summary="Xem số dư ví",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(response=200, description="Thông tin ví"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải khách hàng")
     * )
     */
    public function show(ShowWalletRequest $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: new WalletResource($this->wallets->forCustomer($request->user())),
            message: 'Lấy thông tin ví thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/wallet/transactions",
     *     operationId="customerWalletTransactions",
     *     tags={"Customer Wallet"},
     *     summary="Xem lịch sử giao dịch ví",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=100)),
     *
     *     @OA\Response(response=200, description="Lịch sử giao dịch ví"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải khách hàng")
     * )
     */
    public function transactions(WalletTransactionIndexRequest $request): JsonResponse
    {
        $result = $this->wallets->transactionsForCustomer(
            $request->user(),
            (int) $request->validated('per_page', 20),
        );

        return $this->paginatedResponse(
            request: $request,
            resource: WalletTransactionResource::collection($result['transactions']),
            paginator: $result['transactions'],
            message: 'Lấy lịch sử giao dịch ví thành công!',
        );
    }
}
