<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderRequestReason;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class RefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $disk = Storage::disk((string) config('filesystems.refund_evidence_disk', 'public'));
        $nextAction = $this->status === 'approved'
            && $this->order->payment?->status === PaymentStatus::Paid
            ? 'wallet_payout'
            : null;

        return [
            'id' => $this->id,
            'refund_number' => $this->refund_number,
            'status' => $this->status,
            'status_label' => $this->statusLabel((string) $this->status),
            'allowed_actions' => $this->allowedActions((string) $this->status, $nextAction),
            'next_action' => $nextAction,
            'order' => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status->value,
                'total_amount' => $this->order->total_amount,
            ],
            'customer' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ],
            'branch' => [
                'id' => $this->order->branch->id,
                'name' => $this->order->branch->name,
            ],
            'requested_amount' => $this->requested_amount,
            'approved_amount' => $this->approved_amount,
            'reason_type' => $this->reason_type,
            'reason_type_label' => OrderRequestReason::tryFrom($this->reason_type)?->label(),
            'reason' => $this->reason,
            'evidence_paths' => $this->evidence_paths,
            'evidence_urls' => collect($this->evidence_paths)
                ->map(fn (string $path): string => $disk->url($path))
                ->all(),
            'reviewer' => $this->reviewedBy === null ? null : [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ],
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'wallet_transaction' => $this->walletTransaction === null ? null : [
                'id' => $this->walletTransaction->id,
                'transaction_number' => $this->walletTransaction->transaction_number,
                'type' => $this->walletTransaction->type->value,
                'direction' => $this->walletTransaction->direction->value,
                'amount' => $this->walletTransaction->amount,
                'balance_after' => $this->walletTransaction->balance_after,
                'reference' => $this->walletTransaction->reference,
            ],
            'refunded_at' => $this->refunded_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'requested' => 'Chờ duyệt',
            'approved' => 'Đã duyệt',
            'rejected' => 'Đã từ chối',
            'refunded' => 'Đã hoàn tiền',
            default => $status,
        };
    }

    /** @return array<int, string> */
    private function allowedActions(string $status, ?string $nextAction): array
    {
        return match ($status) {
            'requested' => ['approve', 'reject'],
            'approved' => $nextAction === null ? [] : [$nextAction],
            default => [],
        };
    }
}
