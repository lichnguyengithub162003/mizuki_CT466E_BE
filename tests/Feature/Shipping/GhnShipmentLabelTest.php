<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.ghn.base_url' => 'https://ghn.test/shiip/public-api',
        'services.ghn.token' => 'label-test-token',
        'services.ghn.shop_id' => '123456',
        'services.ghn.timeout_seconds' => 10,
        'services.ghn.connect_timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

/** @return array{branch: Branch, order: Order, shipment: Shipment} */
function createGhnLabelContext(?Branch $branch = null): array
{
    $token = Str::upper(Str::random(10));
    $branch ??= Branch::query()->create([
        'code' => 'GL'.$token,
        'name' => 'Mizuki Label '.$token,
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
        'status' => 'ready_to_pick',
        'shipping_fee' => 30_000,
        'provider_response' => ['created' => true],
    ]);

    return compact('branch', 'order', 'shipment');
}

function fakeSuccessfulGhnLabel(string $token = 'print-token-123'): void
{
    Http::fake([
        'https://ghn.test/shiip/public-api/v2/a5/gen-token' => Http::response([
            'code' => 200,
            'data' => ['token' => $token],
        ]),
    ]);
}

test('branch manager gets an A5 print token and URL from the stored GHN order code', function (): void {
    $context = createGhnLabelContext();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $context['branch']->id,
    ]);
    $before = $context['shipment']->fresh()->getAttributes();
    fakeSuccessfulGhnLabel('label token/123');

    $this->actingAs($manager)
        ->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/label", [
            'order_codes' => ['FRONTEND-CODE'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.order_id', $context['order']->id)
        ->assertJsonPath('data.ghn_order_code', $context['shipment']->ghn_order_code)
        ->assertJsonPath('data.print_token', 'label token/123')
        ->assertJsonPath(
            'data.print_url',
            'https://ghn.test/a5/public-api/printA5?token=label%20token%2F123',
        )
        ->assertJsonPath('message', 'Tạo phiếu giao hàng GHN thành công!');

    expect($context['shipment']->refresh()->getAttributes())->toBe($before)
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Confirmed);
    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://ghn.test/shiip/public-api/v2/a5/gen-token'
        && $request->hasHeader('Token', 'label-test-token')
        && ! $request->hasHeader('ShopId')
        && $request['order_codes'] === [$context['shipment']->ghn_order_code]
        && $request['order_codes'] !== ['FRONTEND-CODE']);
});

test('GHN print token failure returns a safe error without changing shipment', function (): void {
    $context = createGhnLabelContext();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $before = $context['shipment']->fresh()->getAttributes();
    Http::fake(['*' => Http::response(['code' => 500, 'data' => []], 500)]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/label")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.shipping.0', 'Không thể tạo phiếu giao hàng GHN lúc này');

    expect($context['shipment']->refresh()->getAttributes())->toBe($before);
});

test('missing or non GHN shipment returns not found without calling GHN', function (string $provider): void {
    $context = createGhnLabelContext();

    if ($provider === 'missing') {
        $context['shipment']->delete();
    } else {
        $context['shipment']->update(['provider' => $provider]);
    }

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Http::fake();

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment/label")
        ->assertNotFound();

    Http::assertNothingSent();
})->with(['missing', 'other']);

test('branch manager cannot generate a label for another branch', function (): void {
    $own = createGhnLabelContext();
    $other = createGhnLabelContext();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $own['branch']->id,
    ]);
    Http::fake();

    $this->actingAs($manager)
        ->postJson("/api/v1/admin/orders/{$other['order']->id}/shipment/label")
        ->assertNotFound();

    Http::assertNothingSent();
});

test('customer and guest cannot generate an admin shipment label', function (): void {
    $context = createGhnLabelContext();
    $path = "/api/v1/admin/orders/{$context['order']->id}/shipment/label";

    $this->postJson($path)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))
        ->postJson($path)
        ->assertForbidden();

    expect($context['shipment']->refresh()->status)->toBe('ready_to_pick');
});
