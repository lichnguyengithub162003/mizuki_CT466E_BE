<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\BranchUpdateRequest;
use App\Http\Resources\Admin\AdminBranchResource;
use App\Services\Admin\AdminPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal) {}

    public function index(AdminListRequest $request): JsonResponse
    {
        $items = $this->portal->branches($request->user(), $request->validated());
        return $this->paginatedResponse($request, AdminBranchResource::collection($items), $items, 'Lấy danh sách chi nhánh thành công!');
    }

    public function show(Request $request, int $branch): JsonResponse
    {
        $item = $this->portal->branch($request->user(), $branch);
        return $item === null ? $this->errorResponse('Không tìm thấy chi nhánh', 404) : $this->successResponse($request, new AdminBranchResource($item), 'Lấy chi nhánh thành công!');
    }

    public function update(BranchUpdateRequest $request, int $branch): JsonResponse
    {
        $item = $this->portal->updateBranch($request->user(), $branch, $request->validated());
        return $item === null ? $this->errorResponse('Không tìm thấy chi nhánh', 404) : $this->successResponse($request, new AdminBranchResource($item), 'Cập nhật chi nhánh thành công!');
    }
}
