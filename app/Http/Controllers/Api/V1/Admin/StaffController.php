<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\StaffMutationRequest;
use App\Http\Resources\Admin\StaffResource;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal) {}

    public function index(AdminListRequest $request): JsonResponse
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
}
