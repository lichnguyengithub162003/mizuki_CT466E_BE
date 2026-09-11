<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\StaffAssignmentRequest;
use App\Http\Requests\Admin\StaffListRequest;
use App\Http\Requests\Admin\StaffMutationRequest;
use App\Http\Requests\Admin\StaffTransferPreflightRequest;
use App\Http\Resources\Admin\StaffResource;
use App\Repositories\AdminPortalRepository;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffController extends BaseController
{
    public function __construct(
        private readonly AdminPortalService $portal,
        private readonly AdminPortalRepository $repository,
    ) {
        //
    }

    public function index(StaffListRequest $request): JsonResponse
    {
        $items = $this->portal->staff($request->user(), $request->validated());

        return $this->paginatedResponse($request, StaffResource::collection($items), $items, 'Lấy danh sách nhân viên thành công!');
    }

    public function store(StaffMutationRequest $request): JsonResponse
    {
        return $this->successResponse($request, new StaffResource($this->portal->createStaff($request->user(), $request->validated())), 'Tạo nhân viên thành công!', 201);
    }

    public function show(Request $request, int $staff): JsonResponse
    {
        $item = $this->portal->staffMember($request->user(), $staff);

        return $item === null ? $this->errorResponse('Không tìm thấy nhân viên', 404) : $this->successResponse($request, new StaffResource($item), 'Lấy nhân viên thành công!');
    }

    public function update(StaffMutationRequest $request, int $staff): JsonResponse
    {
        $item = $this->portal->updateStaff($request->user(), $staff, $request->validated());

        return $item === null ? $this->errorResponse('Không tìm thấy nhân viên', 404) : $this->successResponse($request, new StaffResource($item), 'Cập nhật nhân viên thành công!');
    }

    public function assignmentPreflight(StaffTransferPreflightRequest $request, int $staff): JsonResponse
    {
        $result = $this->repository->staffAssignmentPreflight($request->user(), $staff, $request->validated());

        return $result === null
            ? $this->errorResponse('Không tìm thấy nhân viên', 404)
            : $this->successResponse($request, new JsonResource($result), 'Kiểm tra điều kiện chuyển công tác thành công!');
    }

    public function changeAssignment(StaffAssignmentRequest $request, int $staff): JsonResponse
    {
        $item = $this->repository->changeStaffAssignment($request->user(), $staff, $request->validated());

        return $item === null
            ? $this->errorResponse('Không tìm thấy nhân viên', 404)
            : $this->successResponse($request, new StaffResource($item), 'Cập nhật phân công nhân viên thành công!');
    }

    public function changeEmploymentStatus(StaffMutationRequest $request, int $staff): JsonResponse
    {
        $item = $this->repository->changeStaffEmploymentStatus($request->user(), $staff, $request->validated()['status']);

        return $item === null
            ? $this->errorResponse('Không tìm thấy nhân viên', 404)
            : $this->successResponse($request, new StaffResource($item), 'Cập nhật trạng thái làm việc thành công!');
    }

    public function destroy(Request $request, int $staff): JsonResponse
    {
        $item = $this->repository->trashStaff($request->user(), $staff);

        return $item === null
            ? $this->errorResponse('Không tìm thấy nhân viên', 404)
            : $this->successResponse($request, new StaffResource($item), 'Đã chuyển nhân viên vào thùng rác!');
    }

    public function restore(Request $request, int $staff): JsonResponse
    {
        $item = $this->repository->restoreStaff($request->user(), $staff);

        return $item === null
            ? $this->errorResponse('Không tìm thấy nhân viên trong thùng rác', 404)
            : $this->successResponse($request, new StaffResource($item), 'Khôi phục nhân viên thành công!');
    }
}
