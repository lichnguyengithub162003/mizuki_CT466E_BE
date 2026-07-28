<?php

namespace App\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Technician\ListAssignedAppointmentsRequest;
use App\Http\Requests\Technician\UpdateAssignedAppointmentStatusRequest;
use App\Http\Resources\Technician\AppointmentResource;
use App\Services\Technician\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class AppointmentController extends BaseController
{
    public function __construct(
        private readonly AppointmentService $appointments,
    ) {}

    /** @OA\Get(path="/api/v1/technician/appointments", operationId="technicianListAppointments",
     * tags={"Technician Appointments"}, summary="Danh sách lịch hẹn được phân công",
     *
     * @OA\Response(response=200, description="Danh sách lịch hẹn"),
     * @OA\Response(response=403, description="Không có quyền"))
     */
    public function index(ListAssignedAppointmentsRequest $request): JsonResponse
    {
        $paginator = $this->appointments->paginate($request->user(), $request->validated());

        return $this->paginatedResponse(
            $request,
            AppointmentResource::collection($paginator),
            $paginator,
            'Lấy danh sách lịch hẹn được phân công thành công!',
        );
    }

    /** @OA\Get(path="/api/v1/technician/appointments/{id}", operationId="technicianShowAppointment",
     * tags={"Technician Appointments"}, summary="Chi tiết lịch hẹn được phân công",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Chi tiết lịch hẹn"),
     * @OA\Response(response=404, description="Không tìm thấy"))
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond(
            $request,
            $this->appointments->detail($request->user(), $id),
            'Lấy chi tiết lịch hẹn thành công!',
        );
    }

    /** @OA\Post(path="/api/v1/technician/appointments/{id}/start", operationId="technicianStartAppointment",
     * tags={"Technician Appointments"}, summary="Bắt đầu lịch hẹn được phân công",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Đã bắt đầu"),
     * @OA\Response(response=422, description="Trạng thái không hợp lệ"))
     */
    public function start(UpdateAssignedAppointmentStatusRequest $request, int $id): JsonResponse
    {
        return $this->respond(
            $request,
            $this->appointments->start($request->user(), $id, $request->validated()),
            'Bắt đầu lịch hẹn thành công!',
        );
    }

    /** @OA\Post(path="/api/v1/technician/appointments/{id}/complete", operationId="technicianCompleteAppointment",
     * tags={"Technician Appointments"}, summary="Hoàn thành lịch hẹn được phân công",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Đã hoàn thành"),
     * @OA\Response(response=422, description="Trạng thái không hợp lệ"))
     */
    public function complete(UpdateAssignedAppointmentStatusRequest $request, int $id): JsonResponse
    {
        return $this->respond(
            $request,
            $this->appointments->complete($request->user(), $id, $request->validated()),
            'Hoàn thành lịch hẹn thành công!',
        );
    }

    private function respond(Request $request, mixed $appointment, string $message): JsonResponse
    {
        return $appointment === null
            ? $this->errorResponse('Không tìm thấy lịch hẹn!', 404)
            : $this->successResponse($request, new AppointmentResource($appointment), $message);
    }
}
