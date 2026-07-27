<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderService extends BaseService
{
    public function __construct(
        private readonly OrderRepository $orders,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Order::class);

        return $this->orders->paginateForAdmin(
            role: $user->role,
            branchId: $user->branch_id,
            filters: $filters,
            perPage: (int) ($filters['per_page'] ?? 20),
        );
    }

    public function detail(User $user, int $orderId): ?Order
    {
        $order = $this->orders->findForAdmin($orderId, $user->role, $user->branch_id);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);

        return $order;
    }

    public function confirm(User $user, int $orderId): ?Order
    {
        $result = $this->orders->transaction(function () use ($user, $orderId): ?array {
            $order = $this->orders->lockForAdmin($orderId, $user->role, $user->branch_id);

            if ($order === null) {
                return null;
            }

            Gate::forUser($user)->authorize('confirm', $order);

            if ($order->status !== OrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ có thể xác nhận đơn hàng đang chờ xác nhận'],
                ]);
            }

            $previousStatus = $order->status;

            return [
                'order' => $this->orders->markConfirmed($order),
                'previous_status' => $previousStatus,
            ];
        });

        if ($result === null) {
            return null;
        }

        // Dispatch only after the transaction has committed successfully.
        OrderStatusUpdated::dispatch($result['order'], $result['previous_status']);

        return $result['order'];
    }
}
