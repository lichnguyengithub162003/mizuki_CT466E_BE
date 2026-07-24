<?php

namespace App\Http\Controllers\Api\V1\Cashier;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Cashier\AddPosItemRequest;
use App\Http\Requests\Cashier\SearchPosProductsRequest;
use App\Http\Requests\Cashier\UpdatePosCustomerRequest;
use App\Http\Requests\Cashier\UpdatePosItemRequest;
use App\Http\Requests\Cashier\UpdatePosPaymentMethodRequest;
use App\Http\Resources\Cashier\PosDisplayResource;
use App\Http\Resources\Cashier\PosProductResource;
use App\Http\Resources\Cashier\PosSessionResource;
use App\Services\Cashier\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class PosController extends BaseController
{
    public function __construct(
        private readonly PosService $pos,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/cashier/pos/products",
     *     operationId="cashierPosSearchProducts",
     *     tags={"Cashier POS"},
     *     summary="Tìm variant theo tên sản phẩm, SKU hoặc barcode",
     *     @OA\Parameter(name="keyword", in="query", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", maximum=20)),
     *     @OA\Response(response=200, description="Kết quả tìm kiếm"),
     *     @OA\Response(response=403, description="Không có quyền hoặc chưa có chi nhánh"),
     *     @OA\Response(response=422, description="Dữ liệu không hợp lệ")
     * )
     */
    public function products(SearchPosProductsRequest $request): JsonResponse
    {
        $variants = $this->pos->searchProducts($request->user(), $request->validated());

        return $this->successResponse(
            request: $request,
            resource: PosProductResource::collection($variants),
            message: 'Tìm sản phẩm thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/cashier/pos/products/barcode/{barcode}",
     *     operationId="cashierPosFindBarcode",
     *     tags={"Cashier POS"},
     *     summary="Tra chính xác variant bằng barcode",
     *     @OA\Parameter(name="barcode", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Variant tìm thấy"),
     *     @OA\Response(response=404, description="Không tìm thấy sản phẩm")
     * )
     */
    public function barcode(Request $request, string $barcode): JsonResponse
    {
        $variant = $this->pos->findByBarcode($request->user(), $barcode);

        if ($variant === null) {
            return $this->productNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new PosProductResource($variant),
            message: 'Tìm sản phẩm thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/cashier/pos/sessions",
     *     operationId="cashierPosCreateSession",
     *     tags={"Cashier POS"},
     *     summary="Tạo phiên POS",
     *     @OA\Response(response=201, description="Đã tạo phiên POS"),
     *     @OA\Response(response=403, description="Thu ngân chưa được gán chi nhánh")
     * )
     */
    public function storeSession(Request $request): JsonResponse
    {
        $session = $this->pos->createSession($request->user());

        return $this->successResponse(
            request: $request,
            resource: new PosSessionResource($session),
            message: 'Tạo phiên POS thành công!',
            status: 201,
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/cashier/pos/sessions/{code}",
     *     operationId="cashierPosShowSession",
     *     tags={"Cashier POS"},
     *     summary="Xem phiên POS",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Chi tiết phiên POS"),
     *     @OA\Response(response=404, description="Không tìm thấy phiên POS")
     * )
     */
    public function showSession(Request $request, string $code): JsonResponse
    {
        $session = $this->pos->getSession($request->user(), $code);

        return $session === null
            ? $this->sessionNotFound()
            : $this->sessionResponse($request, $session, 'Lấy phiên POS thành công!');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/cashier/pos/sessions/{code}/items",
     *     operationId="cashierPosAddItem",
     *     tags={"Cashier POS"},
     *     summary="Thêm sản phẩm vào phiên POS",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *     @OA\Response(response=200, description="Đã thêm sản phẩm"),
     *     @OA\Response(response=422, description="Không đủ tồn kho")
     * )
     */
    public function storeItem(AddPosItemRequest $request, string $code): JsonResponse
    {
        $session = $this->pos->addItem($request->user(), $code, $request->validated());

        return $session === null
            ? $this->sessionNotFound()
            : $this->sessionResponse($request, $session, 'Thêm sản phẩm thành công!');
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/cashier/pos/sessions/{code}/items/{variantId}",
     *     operationId="cashierPosUpdateItem",
     *     tags={"Cashier POS"},
     *     summary="Cập nhật số lượng sản phẩm",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="variantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *     @OA\Response(response=200, description="Đã cập nhật sản phẩm"),
     *     @OA\Response(response=404, description="Không tìm thấy phiên hoặc sản phẩm")
     * )
     */
    public function updateItem(
        UpdatePosItemRequest $request,
        string $code,
        int $variantId,
    ): JsonResponse {
        $session = $this->pos->updateItem(
            $request->user(),
            $code,
            $variantId,
            (int) $request->validated('quantity'),
        );

        return $session === null
            ? $this->sessionNotFound()
            : $this->sessionResponse($request, $session, 'Cập nhật sản phẩm thành công!');
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/cashier/pos/sessions/{code}/items/{variantId}",
     *     operationId="cashierPosDeleteItem",
     *     tags={"Cashier POS"},
     *     summary="Xóa sản phẩm khỏi phiên POS",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="variantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Đã xóa sản phẩm"),
     *     @OA\Response(response=404, description="Không tìm thấy phiên hoặc sản phẩm")
     * )
     */
    public function destroyItem(Request $request, string $code, int $variantId): JsonResponse
    {
        $session = $this->pos->deleteItem($request->user(), $code, $variantId);

        return $session === null
            ? $this->sessionNotFound()
            : $this->sessionResponse($request, $session, 'Xóa sản phẩm thành công!');
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/cashier/pos/sessions/{code}/customer",
     *     operationId="cashierPosUpdateCustomer",
     *     tags={"Cashier POS"},
     *     summary="Gắn khách hàng hoặc khách vãng lai",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(@OA\JsonContent(type="object")),
     *     @OA\Response(response=200, description="Đã cập nhật khách hàng"),
     *     @OA\Response(response=422, description="Dữ liệu khách hàng không hợp lệ")
     * )
     */
    public function updateCustomer(
        UpdatePosCustomerRequest $request,
        string $code,
    ): JsonResponse {
        $session = $this->pos->updateCustomer($request->user(), $code, $request->validated());

        return $session === null
            ? $this->sessionNotFound()
            : $this->sessionResponse($request, $session, 'Cập nhật khách hàng thành công!');
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/cashier/pos/sessions/{code}/payment-method",
     *     operationId="cashierPosUpdatePayment",
     *     tags={"Cashier POS"},
     *     summary="Chọn phương thức thanh toán POS",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="object")),
     *     @OA\Response(response=200, description="Đã cập nhật phương thức thanh toán"),
     *     @OA\Response(response=422, description="Phương thức không hợp lệ")
     * )
     */
    public function updatePaymentMethod(
        UpdatePosPaymentMethodRequest $request,
        string $code,
    ): JsonResponse {
        $session = $this->pos->updatePaymentMethod(
            $request->user(),
            $code,
            (string) $request->validated('payment_method'),
        );

        return $session === null
            ? $this->sessionNotFound()
            : $this->sessionResponse($request, $session, 'Cập nhật thanh toán thành công!');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/cashier/pos/sessions/{code}/confirm",
     *     operationId="cashierPosConfirm",
     *     tags={"Cashier POS"},
     *     summary="Xác nhận đã nhận tiền và chốt đơn POS",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Đã chốt đơn POS"),
     *     @OA\Response(response=404, description="Không tìm thấy phiên POS"),
     *     @OA\Response(response=422, description="Phiên hoặc tồn kho không hợp lệ")
     * )
     */
    public function confirm(Request $request, string $code): JsonResponse
    {
        $session = $this->pos->confirm($request->user(), $code);

        return $session === null
            ? $this->sessionNotFound()
            : $this->sessionResponse($request, $session, 'Xác nhận thanh toán thành công!');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/pos/display/{code}",
     *     operationId="publicPosDisplay",
     *     tags={"Cashier POS"},
     *     summary="Màn hình đơn hàng dành cho khách tại quầy",
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Thông tin màn hình khách"),
     *     @OA\Response(response=404, description="Không tìm thấy hoặc phiên đã hết hạn")
     * )
     */
    public function display(Request $request, string $code): JsonResponse
    {
        $session = $this->pos->display($code);

        if ($session === null) {
            return $this->sessionNotFound();
        }

        return $this->successResponse(
            request: $request,
            resource: new PosDisplayResource($session),
            message: 'Lấy thông tin thanh toán thành công!',
        );
    }

    private function sessionResponse(
        Request $request,
        mixed $session,
        string $message,
    ): JsonResponse {
        return $this->successResponse(
            request: $request,
            resource: new PosSessionResource($session),
            message: $message,
        );
    }

    private function sessionNotFound(): JsonResponse
    {
        return $this->errorResponse('Không tìm thấy phiên POS', 404);
    }

    private function productNotFound(): JsonResponse
    {
        return $this->errorResponse('Không tìm thấy sản phẩm', 404);
    }
}
