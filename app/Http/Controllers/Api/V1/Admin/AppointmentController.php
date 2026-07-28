<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AssignTechnicianRequest;
use App\Http\Requests\Admin\CreateWalkInAppointmentRequest;
use App\Http\Requests\Admin\ListAppointmentsRequest;
use App\Http\Requests\Admin\UpdateAppointmentStatusRequest;
use App\Http\Resources\Admin\AppointmentResource;
use App\Services\Admin\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class AppointmentController extends BaseController
{
    public function __construct(
        private readonly AppointmentService $appointments,
    ) {}

    /**
     * @OA\Get(path="/api/v1/admin/appointments", operationId="adminListAppointments",
     * tags={"Admin Appointments"}, summary="Danh sách lịch hẹn theo phạm vi chi nhánh",
     *
     * @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     * @OA\Parameter(name="branch_id", in="query", @OA\Schema(type="integer")),
     * @OA\Parameter(name="technician_id", in="query", @OA\Schema(type="integer")),
     * @OA\Parameter(name="appointment_date", in="query", @OA\Schema(type="string", format="date")),
     * @OA\Parameter(name="keyword", in="query", @OA\Schema(type="string")),
     *
     * @OA\Response(response=200, description="Danh sách lịch hẹn"),
     * @OA\Response(response=401, description="Chưa đăng nhập"),
     * @OA\Response(response=403, description="Không có quyền"))
     */
    public function index(ListAppointmentsRequest $request): JsonResponse
    {
        $paginator = $this->appointments->paginate($request->user(), $request->validated());

        return $this->paginatedResponse(
            $request,
            AppointmentResource::collection($paginator),
            $paginator,
            'Lấy danh sách lịch hẹn thành công!',
        );
    }

    /**
     * @OA\Get(path="/api/v1/admin/appointments/{id}", operationId="adminShowAppointment",
     * tags={"Admin Appointments"}, summary="Chi tiết lịch hẹn",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Chi tiết lịch hẹn"),
     * @OA\Response(response=404, description="Không tìm thấy lịch hẹn"))
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $appointment = $this->appointments->detail($request->user(), $id);

        return $appointment === null
            ? $this->notFound()
            : $this->successResponse($request, new AppointmentResource($appointment), 'Lấy chi tiết lịch hẹn thành công!');
    }

    /**
     * @OA\Post(path="/api/v1/admin/appointments/walk-in", operationId="adminCreateWalkInAppointment",
     * tags={"Admin Appointments"}, summary="Tạo lịch hẹn walk-in",
     *
     * @OA\RequestBody(required=true, @OA\JsonContent(
     * required={"branch_id","service_id","appointment_date","start_time"},
     *
     * @OA\Property(property="branch_id", type="integer"),
     * @OA\Property(property="service_id", type="integer"),
     * @OA\Property(property="appointment_date", type="string", format="date"),
     * @OA\Property(property="start_time", type="string", example="09:00"),
     * @OA\Property(property="customer_id", type="integer", nullable=true),
     * @OA\Property(property="customer_name", type="string", nullable=true),
     * @OA\Property(property="customer_phone", type="string", nullable=true))),
     *
     * @OA\Response(response=201, description="Đã tạo lịch walk-in"),
     * @OA\Response(response=422, description="Dữ liệu hoặc slot không hợp lệ"))
     */
    public function walkIn(CreateWalkInAppointmentRequest $request): JsonResponse
    {
        $appointment = $this->appointments->createWalkIn($request->user(), $request->validated());

        return $this->successResponse(
            $request,
            new AppointmentResource($appointment),
            'Tạo lịch hẹn walk-in thành công!',
            201,
        );
    }

    /** @OA\Post(path="/api/v1/admin/appointments/{id}/confirm", operationId="adminConfirmAppointment",
     * tags={"Admin Appointments"}, summary="Xác nhận lịch hẹn",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Đã xác nhận"),
     * @OA\Response(response=422, description="Trạng thái không hợp lệ"))
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        return $this->statusResponse(
            $request,
            $this->appointments->confirm($request->user(), $id),
            'Xác nhận lịch hẹn thành công!',
        );
    }

    /** @OA\Post(path="/api/v1/admin/appointments/{id}/assign-technician", operationId="adminAssignAppointmentTechnician",
     * tags={"Admin Appointments"}, summary="Phân công kỹ thuật viên",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\RequestBody(required=true, @OA\JsonContent(required={"technician_id"},
     *
     * @OA\Property(property="technician_id", type="integer"))),
     *
     * @OA\Response(response=200, description="Đã phân công"),
     * @OA\Response(response=422, description="Kỹ thuật viên không hợp lệ"))
     */
    public function assignTechnician(AssignTechnicianRequest $request, int $id): JsonResponse
    {
        return $this->statusResponse(
            $request,
            $this->appointments->assignTechnician(
                $request->user(),
                $id,
                (int) $request->validated('technician_id'),
            ),
            'Phân công kỹ thuật viên thành công!',
        );
    }

    /** @OA\Post(path="/api/v1/admin/appointments/{id}/start", operationId="adminStartAppointment",
     * tags={"Admin Appointments"}, summary="Bắt đầu thực hiện lịch hẹn",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Đã bắt đầu"),
     * @OA\Response(response=422, description="Trạng thái không hợp lệ"))
     */
    public function start(UpdateAppointmentStatusRequest $request, int $id): JsonResponse
    {
        return $this->statusResponse(
            $request,
            $this->appointments->start($request->user(), $id, $request->validated()),
            'Bắt đầu lịch hẹn thành công!',
        );
    }

    /** @OA\Post(path="/api/v1/admin/appointments/{id}/complete", operationId="adminCompleteAppointment",
     * tags={"Admin Appointments"}, summary="Hoàn thành lịch hẹn",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Đã hoàn thành"),
     * @OA\Response(response=422, description="Trạng thái không hợp lệ"))
     */
    public function complete(UpdateAppointmentStatusRequest $request, int $id): JsonResponse
    {
        return $this->statusResponse(
            $request,
            $this->appointments->complete($request->user(), $id, $request->validated()),
            'Hoàn thành lịch hẹn thành công!',
        );
    }

    /** @OA\Post(path="/api/v1/admin/appointments/{id}/cancel", operationId="adminCancelAppointment",
     * tags={"Admin Appointments"}, summary="Hủy lịch hẹn",
     *
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     * @OA\Response(response=200, description="Đã hủy"),
     * @OA\Response(response=422, description="Trạng thái không hợp lệ"))
     */
    public function cancel(UpdateAppointmentStatusRequest $request, int $id): JsonResponse
    {
        return $this->statusResponse(
            $request,
            $this->appointments->cancel($request->user(), $id, $request->validated()),
            'Hủy lịch hẹn thành công!',
        );
    }

    private function statusResponse(Request $request, mixed $appointment, string $message): JsonResponse
    {
        return $appointment === null
            ? $this->notFound()
            : $this->successResponse($request, new AppointmentResource($appointment), $message);
    }

    private function notFound(): JsonResponse
    {
        return $this->errorResponse('Không tìm thấy lịch hẹn!', 404);
    }
}
