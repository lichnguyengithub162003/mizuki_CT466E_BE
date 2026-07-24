<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use Illuminate\Support\Facades\Log;

class LogOrderStatusUpdated
{
    public function handle(OrderStatusUpdated $event): void
    {
        Log::info('Order status updated', [
            'order_id' => $event->order->id,
            'previous_status' => $event->previousStatus->value,
            'current_status' => $event->order->status->value,
        ]);
    }
}
