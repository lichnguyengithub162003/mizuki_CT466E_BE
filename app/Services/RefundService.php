<?php

namespace App\Services;

use App\Enums\OrderRequestReason;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\RefundRepository;
use App\Services\Media\MediaKeyGenerator;
use App\Services\Media\PrivateFileServiceContract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RefundService extends BaseService
{
    public function __construct(
        private readonly RefundRepository $refunds,
        private readonly OrderRepository $orders,
        private readonly PrivateFileServiceContract $privateFiles,
        private readonly MediaKeyGenerator $mediaKeys,
    ) {}

    /**
     * @param  array{reason_type: string, reason?: string|null}  $data
     * @param  array<int, UploadedFile>  $evidence
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

        $uploadedKeys = [];

        try {
            return $this->refunds->transaction(function () use ($user, $orderId, $data, $evidence, &$uploadedKeys): Refund {
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

                $refund = $this->refunds->createRefund([
                    'refund_number' => 'RF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),
                    'order_id' => $lockedOrder->id,
                    'user_id' => $user->id,
                    'status' => 'requested',
                    'requested_amount' => $lockedOrder->total_amount,
                    'reason_type' => $reasonType->value,
                    'reason' => $reason,
                    'evidence_paths' => [],
                ]);

                foreach ($evidence as $file) {
                    [$type, $extension, $mimeType] = $this->classifyEvidence($file);
                    $key = $type === 'image'
                        ? $this->mediaKeys->refundImage($refund->id, $extension)
                        : $this->mediaKeys->refundVideo($refund->id, $extension);

                    // Include the attempted key so cleanup also covers ambiguous
                    // failures where the provider wrote the object before erroring.
                    $uploadedKeys[] = $key;
                    $this->privateFiles->put(
                        key: $key,
                        contents: $file,
                        mimeType: $mimeType,
                        originalName: $file->getClientOriginalName(),
                    );
                }

                $refund = $this->refunds->updateEvidencePaths($refund, $uploadedKeys);

                $lockedOrder->fill(['status' => OrderStatus::RefundRequested])->save();

                return $refund;
            }, 1);
        } catch (UniqueConstraintViolationException) {
            $this->cleanupUploadedEvidence($uploadedKeys);
            $this->refundError('Đơn hàng đã có yêu cầu hoàn tiền');
        } catch (Throwable $exception) {
            $this->cleanupUploadedEvidence($uploadedKeys);
            throw $exception;
        }
    }

    /** @return array{0: 'image'|'video', 1: 'jpg'|'png'|'mp4', 2: string} */
    private function classifyEvidence(UploadedFile $file): array
    {
        $mimeType = (string) $file->getMimeType();

        return match ($mimeType) {
            'image/jpeg' => ['image', 'jpg', $mimeType],
            'image/png' => ['image', 'png', $mimeType],
            'video/mp4' => ['video', 'mp4', $mimeType],
            default => throw ValidationException::withMessages([
                'evidence' => ['File bằng chứng chỉ hỗ trợ JPG, JPEG, PNG hoặc MP4'],
            ]),
        };
    }

    /** @param array<int, string> $keys */
    private function cleanupUploadedEvidence(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->privateFiles->delete($key);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
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
