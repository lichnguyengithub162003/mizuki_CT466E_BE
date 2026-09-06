<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderRequestReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReturnStatus;
use App\Http\Resources\Concerns\SerializesMedia;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    use SerializesMedia;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $returnStatus = $this->effectiveReturnStatus();
        $inspectionStatus = $this->effectiveInspectionStatus($returnStatus);
        $settlementMethod = $this->effectiveSettlementMethod();
        $settlementStatus = $this->effectiveSettlementStatus();
        $nextAction = $this->status === 'approved'
            && $this->order->payment?->status === PaymentStatus::Paid
            && $this->inspectionAllowsSettlement($inspectionStatus)
            ? match ($settlementMethod) {
                'wallet' => 'wallet_payout',
                'vnpay', 'momo', 'zalopay' => 'manual_settlement',
                default => null,
            }
        : null;
        $firstItem = $this->order->items->first();
        $imageUrl = $firstItem?->productVariant?->images?->sortByDesc('is_primary')->first()?->image_url
            ?? $firstItem?->productVariant?->product?->images?->sortByDesc('is_primary')->first()?->image_url;
        $returnActions = $this->returnActions($returnStatus, $inspectionStatus);
        $origin = $this->effectiveOrigin();
        $destination = $this->settlementDestination($settlementMethod);

        return [
            'id' => $this->id,
            'refund_number' => $this->refund_number,
            'status' => $this->status,
            'status_label' => $this->statusLabel((string) $this->status),
            'allowed_actions' => array_values(array_merge(
                $this->allowedActions((string) $this->status, $nextAction),
                $returnActions,
            )),
            'next_action' => $nextAction,
            'origin' => [
                'value' => $origin,
                'label' => $this->originLabel($origin),
            ],
            'return' => [
                'required' => (bool) $this->return_required,
                'status' => $returnStatus->value,
                'status_label' => $returnStatus->label(),
                'inspection_status' => $inspectionStatus,
                'inspection_label' => $this->inspectionLabel($inspectionStatus),
                'received_at' => $this->return_received_at?->toISOString(),
                'restocked_at' => $this->restocked_at?->toISOString(),
                'allowed_actions' => $returnActions,
            ],
            'settlement' => [
                'method' => $settlementMethod,
                'method_label' => $this->settlementMethodLabel($settlementMethod),
                'destination' => $destination,
                'destination_label' => $this->settlementDestinationLabel($destination),
                'status' => $settlementStatus,
                'status_label' => $this->settlementStatusLabel($settlementStatus),
                'reference' => $this->settlement_reference,
            ],
            'image_url' => $this->mediaUrl($imageUrl),
            'item_count' => $this->order->items->count(),
            'items' => $this->order->items->map(function (OrderItem $item): array {
                $image = $item->productVariant?->images?->sortByDesc('is_primary')->first()?->image_url
                    ?? $item->productVariant?->product?->images?->sortByDesc('is_primary')->first()?->image_url;

                return [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'variant_name' => $item->variant_name,
                    'sku' => $item->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'image_url' => $this->mediaUrl($image),
                ];
            })->values()->all(),
            'refund_scope' => 'full_order',
            'destination' => $settlementMethod ?? 'pending',
            'settlement_method' => $settlementMethod,
            'settlement_reference' => $this->settlement_reference,
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
            'evidence_count' => count($this->evidence_paths ?? []),
            'has_evidence' => count($this->evidence_paths ?? []) > 0,
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

    private function effectiveReturnStatus(): RefundReturnStatus
    {
        if ($this->return_status instanceof RefundReturnStatus) {
            return $this->return_status;
        }

        return $this->return_required
            ? RefundReturnStatus::AwaitingReturn
            : RefundReturnStatus::NotRequired;
    }

    private function effectiveInspectionStatus(RefundReturnStatus $returnStatus): ?string
    {
        if (in_array($this->return_inspection_status, [
            'pending',
            'accepted_restockable',
            'accepted_not_restockable',
            'rejected',
        ], true)) {
            return $this->return_inspection_status;
        }

        return match ($returnStatus) {
            RefundReturnStatus::NotRequired => null,
            RefundReturnStatus::Restocked => 'accepted_restockable',
            RefundReturnStatus::NotRestockable => 'accepted_not_restockable',
            default => 'pending',
        };
    }

    private function inspectionAllowsSettlement(?string $inspectionStatus): bool
    {
        return ! $this->return_required || in_array($inspectionStatus, [
            'accepted_restockable',
            'accepted_not_restockable',
        ], true);
    }

    private function effectiveOrigin(): string
    {
        if (in_array($this->origin, ['customer_return', 'order_cancellation', 'admin_or_system'], true)) {
            return $this->origin;
        }

        if ($this->order->status->value === 'cancelled'
            || in_array($this->reason_type, ['order_cancellation', 'order_cancelled', 'cancelled'], true)) {
            return 'order_cancellation';
        }

        return 'customer_return';
    }

    private function effectiveSettlementMethod(): ?string
    {
        if ($this->settlement_method === 'no_payout') {
            return 'no_payout';
        }

        $paymentMethod = $this->order->payment?->method ?? $this->order->payment_method;

        return match ($paymentMethod) {
            PaymentMethod::Wallet, PaymentMethod::Cash, PaymentMethod::BankTransfer => 'wallet',
            PaymentMethod::VNPay => 'vnpay',
            default => in_array($this->settlement_method, [
                'wallet', 'vnpay', 'momo', 'zalopay', 'manual_external', 'no_payout',
            ], true) ? $this->settlement_method : null,
        };
    }

    private function effectiveSettlementStatus(): ?string
    {
        if (in_array($this->settlement_status, ['pending', 'processing', 'succeeded', 'failed'], true)) {
            return $this->settlement_status;
        }

        return match ($this->status) {
            'requested', 'approved' => 'pending',
            'refunded' => 'succeeded',
            default => null,
        };
    }

    private function settlementDestination(?string $method): ?string
    {
        return match ($method) {
            'wallet' => 'wallet',
            'vnpay' => 'vnpay_original',
            'momo' => 'momo_original',
            'zalopay' => 'zalopay_original',
            'no_payout' => 'none',
            default => $method,
        };
    }

    private function originLabel(string $origin): string
    {
        return match ($origin) {
            'customer_return' => 'Yêu cầu hoàn sau giao hàng',
            'order_cancellation' => 'Hoàn tiền do hủy đơn',
            'admin_or_system' => 'Hoàn tiền do quản trị viên hoặc hệ thống',
            default => $origin,
        };
    }

    private function inspectionLabel(?string $status): string
    {
        return match ($status) {
            null => 'Không cần kiểm tra',
            'pending' => 'Chờ kiểm tra',
            'accepted_restockable' => 'Chấp nhận hoàn và nhập lại kho',
            'accepted_not_restockable' => 'Chấp nhận hoàn, không nhập lại kho',
            'rejected' => 'Không đạt điều kiện hoàn tiền',
            default => $status,
        };
    }

    private function settlementMethodLabel(?string $method): ?string
    {
        return match ($method) {
            'wallet' => 'Ví Mizuki',
            'vnpay' => 'VNPAY',
            'momo' => 'MoMo',
            'zalopay' => 'ZaloPay',
            'manual_external' => 'Đối soát ngoài hệ thống',
            'no_payout' => 'Không phát sinh chi trả',
            default => $method,
        };
    }

    private function settlementDestinationLabel(?string $destination): ?string
    {
        return match ($destination) {
            'wallet' => 'Ví Mizuki',
            'vnpay_original' => 'Giao dịch VNPAY gốc',
            'momo_original' => 'Giao dịch MoMo gốc',
            'zalopay_original' => 'Giao dịch ZaloPay gốc',
            'none' => 'Không phát sinh chi trả',
            default => $destination,
        };
    }

    private function settlementStatusLabel(?string $status): ?string
    {
        return match ($status) {
            'pending' => 'Chờ hoàn tiền',
            'processing' => 'Đang hoàn tiền',
            'succeeded' => 'Hoàn tiền thành công',
            'failed' => 'Hoàn tiền thất bại',
            default => null,
        };
    }

    /** @return array<int, string> */
    private function returnActions(RefundReturnStatus $returnStatus, ?string $inspectionStatus): array
    {
        if (! $this->return_required) {
            return [];
        }

        return match ($returnStatus) {
            RefundReturnStatus::AwaitingReturn, RefundReturnStatus::InTransit => ['receive_return'],
            RefundReturnStatus::Received => $inspectionStatus === 'pending'
                ? ['restock', 'mark_not_restockable', 'reject_return_inspection']
                : [],
            default => [],
        };
    }
}
