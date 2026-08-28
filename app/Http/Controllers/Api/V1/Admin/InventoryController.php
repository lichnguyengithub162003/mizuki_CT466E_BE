<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\InventoryAdjustmentRequest;
use App\Http\Resources\Admin\InventoryResource;
use App\Http\Resources\Admin\InventoryTransactionResource;
use App\Services\Admin\AdminPortalService;
use DomainException;
use Illuminate\Http\JsonResponse;

class InventoryController extends BaseController
{
    public function __construct(private readonly AdminPortalService $portal) {}

    public function index(AdminListRequest $request): JsonResponse
    {
        $items = $this->portal->inventory($request->user(), $request->validated());
        return $this->paginatedResponse($request, InventoryResource::collection($items), $items, 'Lấy tồn kho thành công!');
    }

    public function transactions(AdminListRequest $request, int $inventory): JsonResponse
    {
        $items = $this->portal->inventoryTransactions($request->user(), $inventory, (int) ($request->validated('per_page') ?? 15));
        return $items === null ? $this->errorResponse('Không tìm thấy tồn kho', 404)
            : $this->paginatedResponse($request, InventoryTransactionResource::collection($items), $items, 'Lấy lịch sử tồn kho thành công!');
    }

    public function adjust(InventoryAdjustmentRequest $request, int $inventory): JsonResponse
    {
        try {
            $item = $this->portal->adjustInventory($request->user(), $inventory, $request->validated());
        } catch (DomainException $exception) {
            return $this->errorResponse($exception->getMessage(), 422, ['errors' => ['quantity_delta' => [$exception->getMessage()]]]);
        }
        return $item === null ? $this->errorResponse('Không tìm thấy tồn kho', 404)
            : $this->successResponse($request, new InventoryResource($item), 'Điều chỉnh tồn kho thành công!');
    }
}
