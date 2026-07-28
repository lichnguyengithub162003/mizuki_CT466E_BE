<?php

namespace App\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\StaffSkinProfileResource;
use App\Services\Technician\SkinProfileService;
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
     *     path="/api/v1/technician/customers/{customer}/skin-profile",
     *     operationId="technicianShowCustomerSkinProfile",
     *     tags={"Staff Skin Profile"},
     *     summary="Xem hồ sơ da khách hàng được phân công",
     *
     *     @OA\Parameter(name="customer", in="path", required=true,
     *
     *         @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Hồ sơ da khách hàng"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không có quyền"),
     *     @OA\Response(response=404, description="Khách hàng không thuộc lịch được phân công")
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
