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
            'status_label' => $this->status === 'requested' ? 'Chờ duyệt' : $this->status,
            'requested_amount' => $this->requested_amount,
            'approved_amount' => $this->approved_amount,
            'reason_type' => $this->reason_type,
            'reason_type_label' => OrderRequestReason::tryFrom($this->reason_type)?->label(),
            'reason' => $this->reason,
            'evidence_paths' => $this->evidence_paths,
            'evidence_urls' => collect($this->evidence_paths)
                ->map(fn (string $path): string => $disk->url($path))
                ->all(),
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'refunded_at' => $this->refunded_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
