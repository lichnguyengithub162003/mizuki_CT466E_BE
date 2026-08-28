<?php

namespace App\Support\Customer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;

final class OrderAvailableActions
{
    /** @return array<string, bool> */
    public static function for(Order $order): array
    {
        $payment = $order->payment;
        $shipment = $order->shipment;
        $hasRefund = $order->relationLoaded('refunds')
            ? $order->refunds->isNotEmpty()
            : $order->refunds()->exists();

        return [
            'can_cancel' => in_array(
                $order->status,
                [OrderStatus::Pending, OrderStatus::Confirmed],
                true,
            ),
            'can_request_refund' => $order->status === OrderStatus::Delivered && ! $hasRefund,
            'can_track' => $order->fulfillment_method === 'shipping'
                && $shipment !== null
                && filled($shipment->ghn_order_code),
            'can_repurchase' => false,
            'can_retry_payment' => $order->payment_method === PaymentMethod::VNPay
                && $order->status === OrderStatus::Pending
                && $payment?->method === PaymentMethod::VNPay
                && in_array($payment?->status, [PaymentStatus::Pending, PaymentStatus::Failed], true),
        ];
    }
}
