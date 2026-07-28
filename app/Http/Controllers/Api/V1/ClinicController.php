<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Clinic\AvailableSlotsRequest;
use App\Http\Resources\Clinic\AvailableSlotsResource;
use App\Http\Resources\Clinic\ClinicBranchResource;
use App\Http\Resources\Clinic\ClinicServiceResource;
use App\Services\ClinicService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class ClinicController extends BaseController
{
    public function __construct(
        private readonly ClinicService $clinics,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/clinics",
     *     operationId="listPublicClinics",
     *     tags={"Clinics"},
     *     summary="List active clinic branches",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Active clinic and hybrid branches",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                     @OA\Property(property="id", type="integer", example=2),
     *                     @OA\Property(property="name", type="string", example="Mizuki Clinic Can Tho"),
     *                     @OA\Property(property="code", type="string", example="DEV-CLINIC-CT"),
     *                     @OA\Property(property="phone", type="string", example="02923889999"),
     *                     @OA\Property(property="address", type="string", example="Ninh Kieu, Can Tho"),
     *                     @OA\Property(property="province_code", type="string", example="CT"),
     *                     @OA\Property(property="branch_type", type="string", enum={"clinic", "hybrid"}),
     *                     @OA\Property(
     *                         property="business_hours",
     *                         type="array",
     *
     *                         @OA\Items(
     *                             type="object",
     *
     *                             @OA\Property(property="weekday", type="integer", minimum=0, maximum=6),
     *                             @OA\Property(property="opens_at", type="string", nullable=true, example="09:00:00"),
     *                             @OA\Property(property="closes_at", type="string", nullable=true, example="20:00:00"),
     *                             @OA\Property(property="is_closed", type="boolean", example=false)
     *                         )
     *                     )
     *                 )
     *             ),
     *             @OA\Property(property="message", type="string", example="Clinic list loaded successfully"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: ClinicBranchResource::collection($this->clinics->getActiveClinics()),
            message: 'Lấy danh sách cơ sở chăm sóc da thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/clinics/{branchId}/services",
     *     operationId="listPublicClinicServices",
     *     tags={"Clinics"},
     *     summary="List active services enabled at a clinic branch",
     *
     *     @OA\Parameter(name="branchId", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Available clinic services",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Skin care treatment"),
     *                     @OA\Property(property="slug", type="string", example="skin-care-treatment"),
     *                     @OA\Property(property="category", type="string", example="skin_care"),
     *                     @OA\Property(property="short_description", type="string", nullable=true),
     *                     @OA\Property(property="description", type="string", nullable=true),
     *                     @OA\Property(property="image_url", type="string", nullable=true),
     *                     @OA\Property(property="duration_minutes", type="integer", example=60),
     *                     @OA\Property(property="price", type="integer", format="int64", example=450000),
     *                     @OA\Property(property="is_available", type="boolean", example=true),
     *                     @OA\Property(property="capacity", type="integer", example=2)
     *                 )
     *             ),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Clinic branch not found")
     * )
     */
    public function services(Request $request, int $branchId): JsonResponse
    {
        try {
            $result = $this->clinics->getClinicServices($branchId);
        } catch (ModelNotFoundException) {
            return $this->clinicNotFoundResponse();
        }

        return $this->successResponse(
            request: $request,
            resource: ClinicServiceResource::collection($result['services']),
            message: 'Lấy danh sách dịch vụ thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/clinics/{branchId}/services/{serviceId}/slots",
     *     operationId="listPublicClinicSlots",
     *     tags={"Clinics"},
     *     summary="List read-only booking slots on a 30-minute grid",
     *
     *     @OA\Parameter(name="branchId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="serviceId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         required=true,
     *         description="Booking date from today through 90 days ahead",
     *
     *         @OA\Schema(type="string", format="date", example="2026-07-29")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Slot availability; this response does not reserve a slot",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="branch",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="code", type="string"),
     *                     @OA\Property(property="branch_type", type="string")
     *                 ),
     *                 @OA\Property(
     *                     property="service",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="duration_minutes", type="integer"),
     *                     @OA\Property(property="price", type="integer", format="int64"),
     *                     @OA\Property(property="capacity", type="integer")
     *                 ),
     *                 @OA\Property(property="date", type="string", format="date"),
     *                 @OA\Property(property="timezone", type="string", example="Asia/Ho_Chi_Minh"),
     *                 @OA\Property(
     *                     property="slots",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="object",
     *
     *                         @OA\Property(property="start_at", type="string", format="date-time"),
     *                         @OA\Property(property="end_at", type="string", format="date-time"),
     *                         @OA\Property(property="available", type="boolean"),
     *                         @OA\Property(property="remaining_capacity", type="integer")
     *                     )
     *                 )
     *             ),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Clinic or enabled service not found"),
     *     @OA\Response(response=422, description="Invalid, past, or more than 90 days future date")
     * )
     */
    public function slots(
        AvailableSlotsRequest $request,
        int $branchId,
        int $serviceId,
    ): JsonResponse {
        try {
            $result = $this->clinics->getAvailableSlots(
                $branchId,
                $serviceId,
                (string) $request->validated('date'),
            );
        } catch (ModelNotFoundException) {
            return $this->clinicNotFoundResponse();
        }

        return $this->successResponse(
            request: $request,
            resource: new AvailableSlotsResource($result),
            message: 'Lấy danh sách khung giờ trống thành công!',
        );
    }

    private function clinicNotFoundResponse(): JsonResponse
    {
        return $this->errorResponse(
            message: 'Không tìm thấy cơ sở chăm sóc da hoặc dịch vụ phù hợp!',
            status: 404,
        );
    }
}
