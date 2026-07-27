<?php

use App\Models\Order;
use App\Models\Shipment;

test('it casts shipment fee, provider payload, and lifecycle timestamps', function (): void {
    $shipment = new Shipment([
        'shipping_fee' => '30000',
        'provider_response' => ['status' => 'ready_to_pick'],
        'shipped_at' => '2026-06-22 11:00:00',
    ]);

    expect($shipment->shipping_fee)->toBeInt()->toBe(30000)
        ->and($shipment->provider_response)->toBe(['status' => 'ready_to_pick'])
        ->and($shipment->shipped_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it belongs to an order', function (): void {
    $shipment = new Shipment();

    expect($shipment->order()->getRelated())->toBeInstanceOf(Order::class);
});
