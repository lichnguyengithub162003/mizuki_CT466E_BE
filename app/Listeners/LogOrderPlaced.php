<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Support\Facades\Log;

class LogOrderPlaced
{
    public function handle(OrderPlaced $event): void
    {
        Log::info('Customer order placed', [
            'order_id' => $event->order->id,
            'order_number' => $event->order->order_number,
            'user_id' => $event->order->user_id,
        ]);
    }
}
