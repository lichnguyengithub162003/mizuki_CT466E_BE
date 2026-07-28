<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\StaffSkinProfileResource;
use App\Services\Admin\SkinProfileService;
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
     *     path="/api/v1/admin/customers/{customer}/skin-profile",
     *     operationId="adminShowCustomerSkinProfile",
     *     tags={"Staff Skin Profile"},
     *     summary="Xem hồ sơ da khách hàng theo phạm vi quản lý",
     *
     *     @OA\Parameter(name="customer", in="path", required=true,
     *
     *         @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Hồ sơ da khách hàng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền"),
     *     @OA\Response(response=404, description="Không tìm thấy khách hàng phù hợp")
     * )
     */
    public function show(Request $request, int $customer): JsonResponse
    {
        $result = $this->profiles->show($request->user(), $customer);

        return $result === null
            ? $this->errorResponse('Không tìm thấy khách hàng hoặc hồ sơ da phù hợp!', 404)
            : $this->successResponse(
                $request,
                new StaffSkinProfileResource($result),
                'Lấy hồ sơ da khách hàng thành công!',
            );
    }
}
