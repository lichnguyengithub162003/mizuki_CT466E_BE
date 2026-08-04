<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BranchResource;
use App\Services\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends BaseController
{
    public function __construct(
        private readonly BranchService $branchService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $request,
            BranchResource::collection($this->branchService->getActiveBranches()),
            'Lấy danh sách chi nhánh thành công!',
        );
    }
}
