<?php

namespace App\Http\Resources\Customer;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $refund = $this->refunds->first();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'delivery_method' => $this->fulfillment_method === 'shipping' ? 'delivery' : 'pickup',
            'payment_method' => $this->payment_method->value,
            'payment_status' => $this->payment?->status->value,
            'payment_status_label' => $this->payment?->status->label(),
            'payment' => $this->payment === null ? null : [
                'id' => $this->payment->id,
                'payment_number' => $this->payment->payment_number,
                'method' => $this->payment->method->value,
                'status' => $this->payment->status->value,
                'status_label' => $this->payment->status->label(),
                'amount' => $this->payment->amount,
                'provider' => $this->payment->provider,
                'transaction_reference' => $this->payment->transaction_reference,
                'paid_at' => $this->payment->paid_at?->toISOString(),
                'failed_at' => $this->payment->failed_at?->toISOString(),
                'cancelled_at' => $this->payment->cancelled_at?->toISOString(),
                'refunded_at' => $this->payment->refunded_at?->toISOString(),
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ],
            'delivery_address' => $this->fulfillment_method !== 'shipping' ? null : [
                'address_id' => $this->user_address_id,
                'recipient_name' => $this->recipient_name,
                'recipient_phone' => $this->recipient_phone,
                'province_code' => $this->province_code,
                'ghn_district_id' => $this->ghn_district_id,
                'ghn_ward_code' => $this->ghn_ward_code,
                'full_address' => $this->shipping_address,
            ],
            'applied_promotion' => $this->promotion_id === null ? null : [
                'id' => $this->promotion_id,
                'code' => $this->promotion?->code,
                'name' => $this->promotion?->name,
                'discount_amount' => $this->discount_amount,
            ],
            'shipment' => $this->shipment === null ? null : [
                'provider' => $this->shipment->provider,
                'tracking_code' => $this->shipment->ghn_order_code,
                'status' => $this->shipment->status,
                'shipping_fee' => $this->shipment->shipping_fee,
                'expected_delivery_at' => $this->shipment->expected_delivery_at?->toISOString(),
                'shipped_at' => $this->shipment->shipped_at?->toISOString(),
                'delivered_at' => $this->shipment->delivered_at?->toISOString(),
                'cancelled_at' => $this->shipment->cancelled_at?->toISOString(),
            ],
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'variant_attributes' => $item->variant_attributes,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'can_review' => $this->canReviewItem($item),
                'review' => $this->reviewData($item->review),
            ])->values()->all(),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'shipping_fee' => $this->shipping_fee,
            'total_amount' => $this->total_amount,
            'cancellation' => $this->status->value !== 'cancelled' ? null : [
                'reason_type' => $this->cancellation_reason_type,
                'reason' => $this->cancellation_reason,
                'cancelled_at' => $this->cancelled_at?->toISOString(),
            ],
            'refund' => $refund === null ? null : [
                'id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'status' => $refund->status,
                'status_label' => $this->refundStatusLabel((string) $refund->status),
                'requested_amount' => $refund->requested_amount,
                'approved_amount' => $refund->approved_amount,
                'review_note' => $refund->review_note,
                'reviewed_at' => $refund->reviewed_at?->toISOString(),
                'refunded_at' => $refund->refunded_at?->toISOString(),
            ],
            'placed_at' => $this->placed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
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

    private function canReviewItem(OrderItem $item): bool
    {
        $eligibleOrder = $this->channel === 'counter'
            ? $this->status === OrderStatus::Confirmed
            : $this->status === OrderStatus::Delivered;
        $product = $item->productVariant?->product;

        return $eligibleOrder
            && $product !== null
            && $product->reviews->isEmpty();
    }

    /** @return array<string, mixed>|null */
    private function reviewData(?Review $review): ?array
    {
        if ($review === null || $review->trashed()) {
            return null;
        }

        return [
            'id' => $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title,
            'comment' => $review->comment,
            'is_visible' => (bool) $review->is_visible,
            'reviewed_at' => $review->created_at?->toISOString(),
            'updated_at' => $review->updated_at?->toISOString(),
        ];
    }
}
