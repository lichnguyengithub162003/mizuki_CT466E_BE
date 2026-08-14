<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.ghn.base_url' => 'https://ghn.test/shiip/public-api',
        'services.ghn.token' => 'cancel-test-token',
        'services.ghn.shop_id' => '123456',
        'services.ghn.timeout_seconds' => 10,
        'services.ghn.connect_timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array{branch: Branch, order: Order, shipment: Shipment} */
function createGhnCancellationContext(string $status = 'pending', ?Branch $branch = null): array
{
    $token = Str::upper(Str::random(10));
    $branch ??= Branch::query()->create([
        'code' => 'GC'.$token,
        'name' => 'Mizuki GHN '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
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

    return compact('branch', 'order', 'shipment');
}

function fakeSuccessfulGhnCancellation(): void
{
    Http::fake([
        'https://ghn.test/shiip/public-api/v2/switch-status/cancel' => Http::response([
            'code' => 200,
            'data' => [['order_code' => 'accepted', 'result' => true]],
        ]),
    ]);
}

test('branch manager cancels a GHN shipment using its provider order code', function (): void {
    CarbonImmutable::setTestNow('2026-08-14 10:00:00');
    $context = createGhnCancellationContext();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $context['branch']->id,
    ]);
    fakeSuccessfulGhnCancellation();

    $this->actingAs($manager)
        ->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/cancel", [
            'order_codes' => ['FRONTEND-CODE'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.ghn_order_code', $context['shipment']->ghn_order_code)
        ->assertJsonPath('message', 'Hủy vận đơn GHN thành công!');

    $shipment = $context['shipment']->refresh();
    expect($shipment->status)->toBe('cancelled')
        ->and($shipment->cancelled_at?->equalTo(now()))->toBeTrue()
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Confirmed);
    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://ghn.test/shiip/public-api/v2/switch-status/cancel'
        && $request->hasHeader('ShopId', '123456')
        && $request['order_codes'] === [$context['shipment']->ghn_order_code]
        && $request['order_codes'] !== ['FRONTEND-CODE']);
});

test('repeated cancellation is idempotent and does not call GHN twice', function (): void {
    $context = createGhnCancellationContext();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    fakeSuccessfulGhnCancellation();
    $this->actingAs($admin);

    $first = $this->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/cancel")
        ->assertOk();
    $second = $this->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/cancel")
        ->assertOk();

    expect($second->json('data'))->toBe($first->json('data'));
    Http::assertSentCount(1);
});

test('non cancellable shipment statuses are rejected', function (string $status): void {
    $context = createGhnCancellationContext($status);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Http::fake();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/cancel")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.status.0', 'Không thể hủy vận đơn ở trạng thái hiện tại');

    expect($context['shipment']->refresh()->status)->toBe($status)
        ->and($context['shipment']->cancelled_at)->toBeNull();
    Http::assertNothingSent();
})->with(['delivered', 'returned', 'failed', 'unknown']);

test('GHN failure leaves shipment and order unchanged', function (): void {
    $context = createGhnCancellationContext('out_for_delivery');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Http::fake(['*' => Http::response(['code' => 500, 'data' => []], 500)]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/cancel")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.shipping.0', 'Không thể hủy vận đơn GHN lúc này');

    expect($context['shipment']->refresh()->status)->toBe('out_for_delivery')
        ->and($context['shipment']->cancelled_at)->toBeNull()
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Confirmed);
});

test('branch manager cannot cancel a shipment from another branch', function (): void {
    $own = createGhnCancellationContext();
    $other = createGhnCancellationContext();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $own['branch']->id,
    ]);
    Http::fake();

    $this->actingAs($manager)
        ->postJson("/api/v1/admin/orders/{$other['order']->id}/shipment/cancel")
        ->assertNotFound();

    expect($other['shipment']->refresh()->status)->toBe('pending');
    Http::assertNothingSent();
});

test('customer and guest cannot cancel an admin shipment', function (): void {
    $context = createGhnCancellationContext();
    $path = "/api/v1/admin/orders/{$context['order']->id}/shipment/cancel";

    $this->postJson($path)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))
        ->postJson($path)
        ->assertForbidden();

    expect($context['shipment']->refresh()->status)->toBe('pending');
});

test('non GHN shipment is not exposed through the cancellation endpoint', function (): void {
    $context = createGhnCancellationContext();
    $context['shipment']->update(['provider' => 'other']);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Http::fake();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/cancel")
        ->assertNotFound();

    Http::assertNothingSent();
});
