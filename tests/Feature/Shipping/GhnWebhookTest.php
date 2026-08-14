<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array{order: Order, shipment: Shipment} */
function createGhnWebhookShipment(string $status = 'pending'): array
{
    $token = Str::upper(Str::random(10));
    $branch = Branch::query()->create([
        'code' => 'WH'.$token,
        'name' => 'Mizuki Webhook '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $customer = User::factory()->create();
    $order = Order::query()->create([
        'order_number' => 'MZ-'.$token,
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'shipping',
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Confirmed,
        'recipient_name' => 'Khách hàng',
        'recipient_phone' => '0901234567',
        'ghn_district_id' => 1444,
        'ghn_ward_code' => '21010',
        'shipping_address' => 'Ninh Kiều, Cần Thơ',
        'subtotal' => 200_000,
        'discount_amount' => 0,
        'shipping_fee' => 30_000,
        'total_amount' => 230_000,
        'placed_at' => now(),
    ]);
    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-'.$token,
        'status' => $status,
        'shipping_fee' => 30_000,
        'provider_response' => ['created' => true],
    ]);

    return compact('order', 'shipment');
}

test('public GHN webhook updates an existing shipment and latest provider payload', function (): void {
    $context = createGhnWebhookShipment();
    $originalOrderStatus = $context['order']->status;
    $payload = [
        'OrderCode' => $context['shipment']->ghn_order_code,
        'Status' => 'delivering',
        'Time' => '2026-08-13T10:00:00+07:00',
    ];

    $this->postJson('/api/v1/shipping/ghn/webhook', $payload)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $context['shipment']->id)
        ->assertJsonPath('data.status', 'out_for_delivery')
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('message', 'Đã tiếp nhận trạng thái vận đơn GHN!')
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);

    $shipment = $context['shipment']->refresh();
    expect($shipment->status)->toBe('out_for_delivery')
        ->and($shipment->provider_response)->toBe($payload)
        ->and($shipment->shipped_at)->not->toBeNull()
        ->and($context['order']->refresh()->status)->toBe($originalOrderStatus);
});

test('duplicate webhook is idempotent and does not update the shipment twice', function (): void {
    CarbonImmutable::setTestNow('2026-08-13 10:00:00');
    $context = createGhnWebhookShipment();
    $payload = [
        'OrderCode' => $context['shipment']->ghn_order_code,
        'Status' => 'transporting',
    ];

    $this->postJson('/api/v1/shipping/ghn/webhook', $payload)
        ->assertOk()
        ->assertJsonPath('data.changed', true);
    $firstUpdatedAt = $context['shipment']->refresh()->updated_at;
    $firstShippedAt = $context['shipment']->shipped_at;
    CarbonImmutable::setTestNow('2026-08-13 11:00:00');

    $this->postJson('/api/v1/shipping/ghn/webhook', $payload)
        ->assertOk()
        ->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.status', 'in_transit');

    expect($context['shipment']->refresh()->updated_at->equalTo($firstUpdatedAt))->toBeTrue()
        ->and($context['shipment']->shipped_at->equalTo($firstShippedAt))->toBeTrue();
});

test('logically identical webhook remains idempotent when associative keys are reordered', function (): void {
    CarbonImmutable::setTestNow('2026-08-13 10:00:00');
    $context = createGhnWebhookShipment();
    $firstPayload = [
        'OrderCode' => $context['shipment']->ghn_order_code,
        'Status' => 'transporting',
        'Data' => [
            'CurrentWarehouse' => ['Id' => 12, 'Name' => 'Can Tho'],
            'Items' => [
                ['Code' => 'A', 'Quantity' => 1],
                ['Code' => 'B', 'Quantity' => 2],
            ],
        ],
    ];

    $this->postJson('/api/v1/shipping/ghn/webhook', $firstPayload)
        ->assertOk()
        ->assertJsonPath('data.changed', true);
    $shipment = $context['shipment']->refresh();
    $firstUpdatedAt = $shipment->updated_at;
    $storedPayload = $shipment->provider_response;
    CarbonImmutable::setTestNow('2026-08-13 11:00:00');

    $reorderedPayload = [
        'Data' => [
            'Items' => [
                ['Quantity' => 1, 'Code' => 'A'],
                ['Quantity' => 2, 'Code' => 'B'],
            ],
            'CurrentWarehouse' => ['Name' => 'Can Tho', 'Id' => 12],
        ],
        'Status' => 'transporting',
        'OrderCode' => $context['shipment']->ghn_order_code,
    ];

    $this->postJson('/api/v1/shipping/ghn/webhook', $reorderedPayload)
        ->assertOk()
        ->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.status', 'in_transit');

    $shipment->refresh();
    expect($shipment->updated_at->equalTo($firstUpdatedAt))->toBeTrue()
        ->and($shipment->provider_response)->toBe($storedPayload)
        ->and($shipment->provider_response['Data']['Items'][0]['Code'])->toBe('A')
        ->and($shipment->provider_response['Data']['Items'][1]['Code'])->toBe('B');
});

test('unknown shipment returns not found without creating a record', function (): void {
    $this->postJson('/api/v1/shipping/ghn/webhook', [
        'OrderCode' => 'GHN-NOT-FOUND',
        'Status' => 'delivering',
    ])->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Không tìm thấy vận đơn GHN');

    $this->assertDatabaseCount('shipments', 0);
});

test('webhook rejects missing or malformed order code', function (mixed $orderCode): void {
    $payload = ['Status' => 'delivering'];

    if ($orderCode !== null) {
        $payload['OrderCode'] = $orderCode;
    }

    $this->postJson('/api/v1/shipping/ghn/webhook', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.OrderCode.0', 'OrderCode không hợp lệ');
})->with([
    'missing' => [null],
    'blank' => [''],
    'non-string' => [[]],
    'too long' => [str_repeat('A', 101)],
]);

test('unsupported GHN status is rejected without updating the shipment', function (): void {
    $context = createGhnWebhookShipment();

    $this->postJson('/api/v1/shipping/ghn/webhook', [
        'OrderCode' => $context['shipment']->ghn_order_code,
        'Status' => 'unknown-provider-status',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.Status.0', 'Trạng thái GHN không được hỗ trợ');

    expect($context['shipment']->refresh()->status)->toBe('pending')
        ->and($context['shipment']->provider_response)->toBe(['created' => true]);
});

test('terminal shipment cannot be downgraded by a later webhook', function (): void {
    CarbonImmutable::setTestNow('2026-08-13 10:00:00');
    $context = createGhnWebhookShipment();
    $deliveredPayload = [
        'OrderCode' => $context['shipment']->ghn_order_code,
        'Status' => 'delivered',
    ];
    $this->postJson('/api/v1/shipping/ghn/webhook', $deliveredPayload)
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered');
    $deliveredAt = $context['shipment']->refresh()->delivered_at;
    $updatedAt = $context['shipment']->updated_at;
    CarbonImmutable::setTestNow('2026-08-13 12:00:00');

    $this->postJson('/api/v1/shipping/ghn/webhook', [
        'OrderCode' => $context['shipment']->ghn_order_code,
        'Status' => 'picking',
    ])->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.changed', false);

    $shipment = $context['shipment']->refresh();
    expect($shipment->status)->toBe('delivered')
        ->and($shipment->provider_response)->toBe($deliveredPayload)
        ->and($shipment->delivered_at->equalTo($deliveredAt))->toBeTrue()
        ->and($shipment->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('non terminal shipment cannot move backward', function (): void {
    $context = createGhnWebhookShipment('out_for_delivery');

    $this->postJson('/api/v1/shipping/ghn/webhook', [
        'OrderCode' => $context['shipment']->ghn_order_code,
        'Status' => 'ready_to_pick',
    ])->assertOk()
        ->assertJsonPath('data.status', 'out_for_delivery')
        ->assertJsonPath('data.changed', false);

    expect($context['shipment']->refresh()->provider_response)->toBe(['created' => true]);
});
