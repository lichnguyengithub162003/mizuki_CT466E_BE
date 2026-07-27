<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\AddCartItemRequest;
use App\Http\Requests\Customer\SelectCartBranchRequest;
use App\Http\Requests\Customer\UpdateCartItemRequest;
use App\Http\Resources\Customer\CartResource;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class CartController extends BaseController
{
    public function __construct(
        private readonly CartService $carts,
    ) {
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/cart",
     *     operationId="showCustomerCart",
     *     tags={"Customer Cart"},
     *     summary="Xem giỏ hàng hiện tại",
     *     @OA\Response(response=200, description="Chi tiết giỏ hàng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải tài khoản khách hàng")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: new CartResource($this->carts->getForUser($request->user())),
            message: 'Lấy giỏ hàng thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/customer/cart/items",
     *     operationId="addCustomerCartItem",
     *     tags={"Customer Cart"},
     *     summary="Thêm biến thể sản phẩm vào giỏ hàng",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"product_variant_id", "quantity"},
     *             @OA\Property(property="product_variant_id", type="integer"),
     *             @OA\Property(property="quantity", type="integer", minimum=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Đã thêm vào giỏ hàng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=422, description="Dữ liệu hoặc tồn kho không hợp lệ")
     * )
     */
    public function store(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->carts->addItem(
            user: $request->user(),
            variantId: (int) $request->validated('product_variant_id'),
            quantity: (int) $request->validated('quantity'),
        );

        return $this->successResponse(
            request: $request,
            resource: new CartResource($cart),
            message: 'Đã thêm vào giỏ hàng!',
        );
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/customer/cart/items/{id}",
     *     operationId="updateCustomerCartItem",
     *     tags={"Customer Cart"},
     *     summary="Cập nhật số lượng sản phẩm trong giỏ",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"quantity"},
     *             @OA\Property(property="quantity", type="integer", minimum=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Đã cập nhật giỏ hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy sản phẩm trong giỏ"),
     *     @OA\Response(response=422, description="Số lượng vượt tồn kho")
     * )
     */
    public function update(UpdateCartItemRequest $request, int $id): JsonResponse
    {
        $cart = $this->carts->updateItem(
            user: $request->user(),
            itemId: $id,
            quantity: (int) $request->validated('quantity'),
        );

        if ($cart === null) {
            return $this->errorResponse(
                message: 'Không tìm thấy sản phẩm trong giỏ hàng',
                status: 404,
            );
        }

        return $this->successResponse(
            request: $request,
            resource: new CartResource($cart),
            message: 'Đã cập nhật giỏ hàng!',
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/customer/cart/items/{id}",
     *     operationId="deleteCustomerCartItem",
     *     tags={"Customer Cart"},
     *     summary="Xóa sản phẩm khỏi giỏ hàng",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Đã xóa sản phẩm khỏi giỏ hàng"),
     *     @OA\Response(response=404, description="Không tìm thấy sản phẩm trong giỏ")
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $cart = $this->carts->removeItem($request->user(), $id);

        if ($cart === null) {
            return $this->errorResponse(
                message: 'Không tìm thấy sản phẩm trong giỏ hàng',
                status: 404,
            );
        }

        return $this->successResponse(
            request: $request,
            resource: new CartResource($cart),
            message: 'Đã xóa sản phẩm khỏi giỏ hàng!',
        );
    }

    /**
     * @OA\Patch(
     *     path="/api/v1/customer/cart/branch",
     *     operationId="selectCustomerCartBranch",
     *     tags={"Customer Cart"},
     *     summary="Chọn chi nhánh cho giỏ hàng",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"branch_id"},
     *             @OA\Property(property="branch_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Đã chọn chi nhánh"),
     *     @OA\Response(response=422, description="Chi nhánh không hợp lệ")
     * )
     */
    public function selectBranch(SelectCartBranchRequest $request): JsonResponse
    {
        $cart = $this->carts->selectBranch(
            user: $request->user(),
            branchId: (int) $request->validated('branch_id'),
        );

        return $this->successResponse(
            request: $request,
            resource: new CartResource($cart),
            message: 'Đã chọn chi nhánh cho giỏ hàng!',
        );
    }
}
