<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\CancelAppointmentRequest;
use App\Http\Requests\Customer\CreateAppointmentRequest;
use App\Http\Requests\Customer\ListAppointmentsRequest;
use App\Http\Resources\Customer\AppointmentResource;
use App\Models\Appointment;
use App\Services\Customer\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Annotations as OA;

class AppointmentController extends BaseController
{
    public function __construct(
        private readonly AppointmentService $appointments,
    ) {}

    /**
     * @OA\Post(
     *     path="/api/v1/customer/appointments",
     *     operationId="customerCreateAppointment",
     *     tags={"Customer Appointments"},
     *     summary="Đặt lịch dịch vụ trực tuyến",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"branch_id", "service_id", "appointment_date", "start_time"},
     *
     *         @OA\Property(property="branch_id", type="integer", example=2),
     *         @OA\Property(property="service_id", type="integer", example=1),
     *         @OA\Property(property="appointment_date", type="string", format="date"),
     *         @OA\Property(property="start_time", type="string", example="09:00"),
     *         @OA\Property(property="customer_note", type="string", nullable=true, maxLength=1000)
     *     )),
     *
     *     @OA\Response(response=201, description="Đặt lịch thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải tài khoản khách hàng"),
     *     @OA\Response(response=422, description="Slot hoặc dữ liệu đặt lịch không hợp lệ"),
     *     @OA\Response(response=429, description="Vượt quá 5 yêu cầu đặt lịch mỗi phút")
     * )
     */
    public function store(CreateAppointmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Appointment::class);
        $appointment = $this->appointments->create($request->user(), $request->validated());

        return $this->successResponse(
            request: $request,
            resource: new AppointmentResource($appointment),
            message: 'Đặt lịch thành công!',
            status: 201,
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/appointments",
     *     operationId="customerAppointmentList",
     *     tags={"Customer Appointments"},
     *     summary="Danh sách lịch hẹn của khách hàng",
     *
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", maximum=100)),
     *
     *     @OA\Response(response=200, description="Danh sách lịch hẹn"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=403, description="Không phải tài khoản khách hàng")
     * )
     */
    public function index(ListAppointmentsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Appointment::class);
        $paginator = $this->appointments->paginate($request->user(), $request->validated());

        return $this->paginatedResponse(
            request: $request,
            resource: AppointmentResource::collection($paginator),
            paginator: $paginator,
            message: 'Lấy danh sách lịch hẹn thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/customer/appointments/{id}",
     *     operationId="customerAppointmentDetail",
     *     tags={"Customer Appointments"},
     *     summary="Chi tiết lịch hẹn của khách hàng",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Chi tiết lịch hẹn"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=404, description="Không tìm thấy lịch hẹn")
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $appointment = $this->appointments->detail($request->user(), $id);

        if ($appointment === null) {
            return $this->errorResponse('Không tìm thấy lịch hẹn!', 404);
        }

        Gate::authorize('view', $appointment);

        return $this->successResponse(
            request: $request,
            resource: new AppointmentResource($appointment),
            message: 'Lấy chi tiết lịch hẹn thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/customer/appointments/{id}/cancel",
     *     operationId="customerCancelAppointment",
     *     tags={"Customer Appointments"},
     *     summary="Hủy lịch hẹn của khách hàng",
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Hủy lịch hẹn thành công"),
     *     @OA\Response(response=401, description="Chưa đăng nhập"),
     *     @OA\Response(response=404, description="Không tìm thấy lịch hẹn"),
     *     @OA\Response(response=422, description="Trạng thái không cho phép hủy")
     * )
     */
    public function cancel(CancelAppointmentRequest $request, int $id): JsonResponse
    {
        $ownedAppointment = $this->appointments->detail($request->user(), $id);

        if ($ownedAppointment === null) {
            return $this->errorResponse('Không tìm thấy lịch hẹn!', 404);
        }

        Gate::authorize('cancel', $ownedAppointment);
        $appointment = $this->appointments->cancel($request->user(), $id);

        if ($appointment === null) {
            return $this->errorResponse('Không tìm thấy lịch hẹn!', 404);
        }

        return $this->successResponse(
            request: $request,
            resource: new AppointmentResource($appointment),
            message: 'Hủy lịch hẹn thành công!',
        );
    }
}
