<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'currency' => 'VND',
            'reference' => $this->reference,
            'description' => $this->description,
            'order' => $this->order === null ? null : [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ],
            'refund' => $this->refund === null ? null : [
                'id' => $this->refund->id,
                'refund_number' => $this->refund->refund_number,
                'status' => $this->refund->status,
                'status_label' => $this->refundStatusLabel((string) $this->refund->status),
                'requested_amount' => $this->refund->requested_amount,
                'approved_amount' => $this->refund->approved_amount,
                'refunded_at' => $this->refund->refunded_at?->toISOString(),
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function refundStatusLabel(string $status): string
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
