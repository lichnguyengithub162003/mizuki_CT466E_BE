<?php

namespace App\Http\Resources\Customer;

use App\Enums\OrderRequestReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class RefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $disk = Storage::disk((string) config('filesystems.refund_evidence_disk', 'public'));

        return [
            'id' => $this->id,
            'refund_number' => $this->refund_number,
            'order_id' => $this->order_id,
            'order_number' => $this->order?->order_number,
            'status' => $this->status,
            'status_label' => $this->statusLabel((string) $this->status),
            'requested_at' => $this->created_at?->toISOString(),
            'accepted_at' => in_array($this->status, ['approved', 'refunded'], true)
                ? $this->reviewed_at?->toISOString()
                : null,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'refunded_at' => $this->refunded_at?->toISOString(),
            'rejected_at' => $this->status === 'rejected'
                ? $this->reviewed_at?->toISOString()
                : null,
            'requested_amount' => $this->requested_amount,
            'approved_amount' => $this->approved_amount,
            'reason_type' => $this->reason_type,
            'reason_type_label' => OrderRequestReason::tryFrom($this->reason_type)?->label(),
            'reason' => $this->reason,
            'evidence_paths' => $this->evidence_paths,
            'evidence_urls' => collect($this->evidence_paths)
                ->map(fn(string $path): string => $disk->url($path))
                ->all(),
            'review_note' => $this->review_note,
            'payment_destination' => $this->paymentDestination(),
            'payment_destination_label' => $this->paymentDestination() === 'wallet'
                ? 'Ví Mizuki'
                : null,
            'product_value' => $this->order?->subtotal,
            'voucher_discount_amount' => $this->order?->discount_amount,
            'received_amount' => $this->status === 'refunded'
                && $this->wallet_transaction_id !== null
                ? ($this->approved_amount ?? $this->requested_amount)
                : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function paymentDestination(): ?string
    {
        return $this->wallet_transaction_id === null ? null : 'wallet';
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
}
