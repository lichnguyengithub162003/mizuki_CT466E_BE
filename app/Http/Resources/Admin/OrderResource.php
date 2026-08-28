<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $refund = $this->refunds->sortByDesc('id')->first();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name ?? $this->customer_name,
                'email' => $this->user?->email,
                'phone' => $this->user?->phone ?? $this->customer_phone,
                'registered' => $this->user_id !== null,
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
            ],
            'channel' => $this->channel,
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
            'delivery_address' => $this->fulfillment_method !== 'shipping' ? null : [
                'address_id' => $this->user_address_id,
                'recipient_name' => $this->recipient_name,
                'recipient_phone' => $this->recipient_phone,
                'province_code' => $this->province_code,
                'ghn_district_id' => $this->ghn_district_id,
                'ghn_ward_code' => $this->ghn_ward_code,
                'full_address' => $this->shipping_address,
            ],
            'shipment' => $this->shipment === null ? null : [
                'id' => $this->shipment->id,
                'provider' => $this->shipment->provider,
                'tracking_code' => $this->shipment->ghn_order_code,
                'status' => $this->shipment->status,
                'shipping_fee' => $this->shipment->shipping_fee,
                'expected_delivery_at' => $this->shipment->expected_delivery_at?->toISOString(),
                'shipped_at' => $this->shipment->shipped_at?->toISOString(),
                'delivered_at' => $this->shipment->delivered_at?->toISOString(),
                'cancelled_at' => $this->shipment->cancelled_at?->toISOString(),
            ],
            'allowed_actions' => $this->allowedActions(),
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'variant_attributes' => $item->variant_attributes,
                'image_url' => $this->itemImageUrl($item),
                'brand_id' => $item->brand_id,
                'brand_name' => $item->brand_name,
                'brand_slug' => $item->brand_slug,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
            ])->values()->all(),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'shipping_fee' => $this->shipping_fee,
            'total_amount' => $this->total_amount,
            'note' => $this->note,
            'cancellation' => $this->cancellation_reason === null ? null : [
                'reason_type' => $this->cancellation_reason_type,
                'reason' => $this->cancellation_reason,
                'cancelled_at' => $this->cancelled_at?->toISOString(),
            ],
            'refund' => $refund === null ? null : [
                'id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'status' => $refund->status,
                'requested_amount' => $refund->requested_amount,
                'approved_amount' => $refund->approved_amount,
                'reviewed_by_user_id' => $refund->reviewed_by_user_id,
                'reviewed_at' => $refund->reviewed_at?->toISOString(),
            ],
            'placed_at' => $this->placed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function itemImageUrl(OrderItem $item): ?string
    {
        $variant = $item->productVariant;

        if ($variant === null) {
            return null;
        }

        return $this->primaryImageUrl($variant->images)
            ?? $this->primaryImageUrl($variant->product?->images ?? collect());
    }

    /** @param  Collection<int, ProductImage>  $images */
    private function primaryImageUrl(Collection $images): ?string
    {
        return ($images->firstWhere('is_primary', true) ?? $images->first())?->image_url;
    }

    /** @return list<string> */
    private function allowedActions(): array
    {
        $actions = match ($this->status) {
            OrderStatus::Pending => ['confirm'],
            OrderStatus::Confirmed => ['process'],
            OrderStatus::Processing => $this->fulfillment_method === 'pickup'
                ? ['complete']
                : ($this->shipment === null ? ['create_shipment'] : []),
            default => [],
        };

        if ($this->shipment?->provider === 'ghn'
            && filled($this->shipment->ghn_order_code)) {
            $actions[] = 'shipment_label';

            if (in_array($this->shipment->status, [
                'pending',
                'ready_to_pick',
                'picking',
                'in_transit',
                'out_for_delivery',
                'delivery_failed',
                'returning',
            ], true)) {
                $actions[] = 'cancel_shipment';
            }
        }

        return array_values(array_unique($actions));
    }
}
