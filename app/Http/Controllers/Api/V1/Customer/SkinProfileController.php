<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\UpdateSkinProfileRequest;
use App\Http\Resources\SkinProfileResource;
use App\Services\Customer\SkinProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class SkinProfileController extends BaseController
{
    public function __construct(
        private readonly SkinProfileService $profiles,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/customer/skin-profile",
     *     operationId="customerShowSkinProfile",
     *     tags={"Customer Skin Profile"},
     *     summary="Xem hồ sơ da của khách hàng đang đăng nhập",
     *
     *     @OA\Response(response=200, description="Hồ sơ da hoặc biểu diễn rỗng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải tài khoản khách hàng")
     * )
     */
    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(
            $request,
            new SkinProfileResource($this->profiles->show($request->user())),
            'Lấy hồ sơ da thành công!',
        );
    }

    /**
     * @OA\Put(
     *     path="/api/v1/customer/skin-profile",
     *     operationId="customerUpdateSkinProfile",
     *     tags={"Customer Skin Profile"},
     *     summary="Tạo hoặc cập nhật hồ sơ da",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *
     *         @OA\Property(property="skin_type", type="string", nullable=true,
     *             enum={"normal","dry","oily","combination","sensitive"}),
     *         @OA\Property(property="concerns", type="array", nullable=true,
     *
     *             @OA\Items(type="string")),
     *
     *         @OA\Property(property="sensitivity_level", type="string", nullable=true,
     *             enum={"low","medium","high"}),
     *         @OA\Property(property="allergies", type="string", nullable=true),
     *         @OA\Property(property="current_products", type="string", nullable=true),
     *         @OA\Property(property="notes", type="string", nullable=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Đã lưu hồ sơ da"),
     *     @OA\Response(response=422, description="Dữ liệu không hợp lệ")
     * )
     */
    public function update(UpdateSkinProfileRequest $request): JsonResponse
    {
        return $this->successResponse(
            $request,
            new SkinProfileResource(
                $this->profiles->update($request->user(), $request->validated()),
            ),
            'Lưu hồ sơ da thành công!',
        );
    }
}
