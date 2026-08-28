<?php

namespace App\Http\Resources\Customer;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\ProductImage;
use App\Models\Review;
use App\Services\Import\ProductImageImportService;
use App\Support\Customer\OrderAvailableActions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $refund = $this->refunds->first();
        $cancellationRequester = $this->cancellationRequester;

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
            'pickup_customer' => $this->fulfillment_method === 'shipping' ? null : [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'address' => null,
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
            'shipment' => $this->fulfillment_method !== 'shipping' || $this->shipment === null ? null : [
                'id' => $this->shipment->id,
                'provider' => $this->shipment->provider,
                'tracking_code' => $this->shipment->ghn_order_code,
                'status' => $this->shipment->status,
                'status_label' => $this->shipment->statusLabel(),
                'shipping_fee' => $this->shipment->shipping_fee,
                'expected_delivery_at' => $this->shipment->expected_delivery_at?->toISOString(),
                'current_location' => $this->shipmentCurrentLocation(),
                'shipped_at' => $this->shipment->shipped_at?->toISOString(),
                'delivered_at' => $this->shipment->delivered_at?->toISOString(),
                'cancelled_at' => $this->shipment->cancelled_at?->toISOString(),
            ],
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_id' => $item->product_id ?? $item->productVariant?->product?->id,
                'product_slug' => $item->product_slug ?? $item->productVariant?->product?->slug,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'variant_attributes' => $item->variant_attributes,
                'brand' => $this->itemBrand($item),
                'original_unit_price' => $item->original_unit_price,
                'final_unit_price' => $item->unit_price,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'image_url' => $this->itemImageUrl($item),
                'can_review' => $this->canReviewItem($item),
                'review' => $this->reviewData($item->review),
            ])->values()->all(),
            'subtotal' => $this->subtotal,
            'product_discount_amount' => 0,
            'discount_amount' => $this->discount_amount,
            'shipping_fee' => $this->fulfillment_method === 'shipping' ? $this->shipping_fee : 0,
            'shipping_discount_amount' => 0,
            'voucher_discount_amount' => $this->discount_amount,
            'total' => $this->total_amount,
            'total_amount' => $this->total_amount,
            'cancellation_requested_by' => $this->cancellation_requested_by,
            'cancellation_requested_at' => $this->cancellation_requested_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_reason_type' => $this->cancellation_reason_type,
            'cancellation_requester_name' => $cancellationRequester?->name,
            'cancellation_requester_type' => $cancellationRequester?->role?->value
                ?? $this->cancellation_requested_by,
            'cancellation' => $this->status->value !== 'cancelled' ? null : [
                'reason_type' => $this->cancellation_reason_type,
                'reason' => $this->cancellation_reason,
                'requested_by' => $this->cancellation_requested_by,
                'requested_at' => $this->cancellation_requested_at?->toISOString(),
                'requester_name' => $cancellationRequester?->name,
                'requester_type' => $cancellationRequester?->role?->value
                    ?? $this->cancellation_requested_by,
                'cancelled_at' => $this->cancelled_at?->toISOString(),
            ],
            'refund' => $refund === null ? null : (new RefundResource($refund))->resolve($request),
            'available_actions' => OrderAvailableActions::for($this->resource),
            'placed_at' => $this->placed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /** @return array{id: int|null, name: string|null, slug: string|null}|null */
    private function itemBrand(OrderItem $item): ?array
    {
        if ($item->brand_id !== null || $item->brand_name !== null || $item->brand_slug !== null) {
            return [
                'id' => $item->brand_id,
                'name' => $item->brand_name,
                'slug' => $item->brand_slug,
            ];
        }

        $brand = $item->productVariant?->product?->brand;

        return $brand === null ? null : [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
        ];
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

    private function itemImageUrl(OrderItem $item): ?string
    {
        $variant = $item->productVariant;

        if ($variant === null) {
            return null;
        }

        return $this->primaryRealImageUrl($variant->images)
            ?? $this->primaryRealImageUrl($variant->product?->images ?? collect());
    }

    /** @param  Collection<int, ProductImage>  $images */
    private function primaryRealImageUrl(Collection $images): ?string
    {
        $realImages = $images->reject(
            fn (ProductImage $image): bool => $image->image_url === ProductImageImportService::FALLBACK_URL,
        );

        return ($realImages->firstWhere('is_primary', true) ?? $realImages->first())?->image_url;
    }

    private function shipmentCurrentLocation(): ?string
    {
        $location = data_get($this->shipment?->provider_response, 'CurrentWarehouse.Name');

        return is_string($location) && filled($location) ? $location : null;
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
