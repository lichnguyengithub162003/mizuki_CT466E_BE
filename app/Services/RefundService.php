<?php

namespace App\Services;

use App\Enums\OrderRequestReason;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\RefundRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RefundService extends BaseService
{
    public function __construct(
        private readonly RefundRepository $refunds,
        private readonly OrderRepository $orders,
    ) {
    }

    /**
     * @param array{reason_type: string, reason?: string|null} $data
     * @param array<int, UploadedFile> $evidence
     */
    public function request(User $user, int $orderId, array $data, array $evidence): ?Refund
    {
        $order = $this->orders->findForUser($orderId, $user->id);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);
        $this->validateRefundable($order);

        if ($this->refunds->existsForOrder($order->id)) {
            $this->refundError('Đơn hàng đã có yêu cầu hoàn tiền');
        }

        $diskName = (string) config('filesystems.refund_evidence_disk', 'public');
        $disk = Storage::disk($diskName);
        $paths = [];

        try {
            foreach ($evidence as $file) {
                $path = $disk->putFile('refund-evidence', $file);

                if ($path === false) {
                    throw new \RuntimeException('Unable to store refund evidence');
                }

                $paths[] = $path;
            }

            return $this->refunds->transaction(function () use ($user, $orderId, $data, $paths): Refund {
                $lockedOrder = $this->orders->lockForUser($orderId, $user->id);

                if ($lockedOrder === null) {
                    throw new AuthorizationException('Không tìm thấy đơn hàng');
                }

                $this->validateRefundable($lockedOrder);

                if ($this->refunds->existsForOrder($lockedOrder->id)) {
                    $this->refundError('Đơn hàng đã có yêu cầu hoàn tiền');
                }

                $reasonType = OrderRequestReason::from($data['reason_type']);
                $reason = trim((string) ($data['reason'] ?? '')) ?: $reasonType->label();

                return $this->refunds->createRefund([
                    'refund_number' => 'RF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
                    'order_id' => $lockedOrder->id,
                    'user_id' => $user->id,
                    'status' => 'requested',
                    'requested_amount' => $lockedOrder->total_amount,
                    'reason_type' => $reasonType->value,
                    'reason' => $reason,
                    'evidence_paths' => $paths,
                ]);
            });
        } catch (Throwable $exception) {
            if ($paths !== []) {
                $disk->delete($paths);
            }

            throw $exception;
        }
    }

    private function validateRefundable(Order $order): void
    {
        if ($order->status !== OrderStatus::Delivered) {
            $this->refundError('Chỉ có thể yêu cầu hoàn tiền cho đơn hàng đã giao');
        }
    }

    private function refundError(string $message): never
    {
        throw ValidationException::withMessages(['refund' => [$message]]);
    }
}
