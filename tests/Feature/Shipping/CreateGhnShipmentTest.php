<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.ghn.base_url' => 'https://ghn.test/shiip/public-api',
        'services.ghn.token' => 'shipment-test-token',
        'services.ghn.shop_id' => '123456',
        'services.ghn.timeout_seconds' => 10,
        'services.ghn.connect_timeout_seconds' => 5,
        'shipping.package.default_length_cm' => 20,
        'shipping.package.default_width_cm' => 15,
        'shipping.package.default_height_cm' => 10,
        'shipping.package.max_dimension_cm' => 200,
        'shipping.package.max_weight_grams' => 30_000,
        'shipping.package.max_insurance_value' => 5_000_000,
    ]);
});

/**
 * @return array{branch: Branch, customer: User, order: Order, variant: ProductVariant}
 */
function createGhnShipmentContext(
    string $fulfillmentMethod = 'shipping',
    ?Branch $branch = null,
): array {
    $token = Str::lower(Str::random(10));
    $branch ??= Branch::query()->create([
        'code' => 'GS'.Str::upper($token),
        'name' => 'Mizuki Shipping '.$token,
        'phone' => '02923888888',
        'address' => '12 Đường 3/2, Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $category = Category::query()->create([
        'name' => 'Shipping '.$token,
        'slug' => 'shipping-category-'.$token,
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Shipping '.$token,
        'slug' => 'shipping-brand-'.$token,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Sản phẩm giao hàng '.$token,
        'slug' => 'shipping-product-'.$token,
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'SHIP-'.Str::upper($token),
        'price' => 150_000,
        'weight' => 250,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $order = Order::query()->create([
        'order_number' => 'MZ-'.Str::upper(Str::random(12)),
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => $fulfillmentMethod,
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Confirmed,
        'recipient_name' => $fulfillmentMethod === 'shipping' ? 'Nguyễn Khách Hàng' : null,
        'recipient_phone' => $fulfillmentMethod === 'shipping' ? '0901234567' : null,
        'province_code' => $fulfillmentMethod === 'shipping' ? 'CT' : null,
        'ghn_district_id' => $fulfillmentMethod === 'shipping' ? 1444 : null,
        'ghn_ward_code' => $fulfillmentMethod === 'shipping' ? '21010' : null,
        'shipping_address' => $fulfillmentMethod === 'shipping'
            ? '20 Nguyễn Văn Cừ, An Khánh, Ninh Kiều, Cần Thơ'
            : null,
        'subtotal' => 300_000,
        'discount_amount' => 0,
        'shipping_fee' => $fulfillmentMethod === 'shipping' ? 30_000 : 0,
        'total_amount' => $fulfillmentMethod === 'shipping' ? 330_000 : 300_000,
        'placed_at' => now(),
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_name' => $product->name,
        'variant_name' => $variant->name,
        'sku' => $variant->sku,
        'unit_price' => 150_000,
        'quantity' => 2,
        'line_total' => 300_000,
    ]);

    return compact('branch', 'customer', 'order', 'variant');
}

function fakeSuccessfulGhnShipment(string $orderCode = 'GHN-ORDER-001'): void
{
    Http::fake(function (Request $request) use ($orderCode) {
        if ($request->url() === 'https://ghn.test/shiip/public-api/v2/shipping-order/available-services') {
            return Http::response([
                'code' => 200,
                'data' => [[
                    'service_id' => 53320,
                    'short_name' => 'Light',
                    'service_type_id' => 2,
                ]],
            ]);
        }

        if ($request->url() === 'https://ghn.test/shiip/public-api/v2/shipping-order/create') {
            return Http::response([
                'code' => 200,
                'data' => [
                    'order_code' => $orderCode,
                    'total_fee' => 30_000,
                    'expected_delivery_time' => '2026-08-20T23:59:59+07:00',
                ],
            ]);
        }

        return Http::response(['code' => 404, 'data' => []], 404);
    });
}

test('branch manager creates a GHN shipment from backend order data', function (): void {
    $context = createGhnShipmentContext();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $context['branch']->id,
    ]);
    fakeSuccessfulGhnShipment('GHN-SAVED-001');
    $this->actingAs($manager);

    $this->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment", [
        'ghn_order_code' => 'FRONTEND-CODE',
        'shipping_fee' => 1,
        'service_id' => 1,
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.order_id', $context['order']->id)
        ->assertJsonPath('data.ghn_order_code', 'GHN-SAVED-001')
        ->assertJsonPath('data.shipping_fee', 30_000)
        ->assertJsonPath('message', 'Tạo vận đơn GHN thành công!')
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $context['order']->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-SAVED-001',
        'shipping_fee' => 30_000,
    ]);
    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://ghn.test/shiip/public-api/v2/shipping-order/create'
        && $request->hasHeader('ShopId', '123456')
        && $request['client_order_code'] === $context['order']->order_number
        && $request['to_name'] === $context['order']->recipient_name
        && $request['to_phone'] === $context['order']->recipient_phone
        && $request['to_district_id'] === $context['order']->ghn_district_id
        && $request['cod_amount'] === $context['order']->total_amount
        && $request['weight'] === 500
        && ! array_key_exists('code', $request['items'][0])
        && $request['items'][0]['quantity'] === 2
        && ! array_key_exists('ghn_order_code', $request->data())
    );
});

test('repeated shipment creation is idempotent and never calls GHN twice', function (): void {
    $context = createGhnShipmentContext();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    fakeSuccessfulGhnShipment('GHN-IDEMPOTENT');
    $this->actingAs($admin);

    $first = $this->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment")
        ->assertOk();
    $second = $this->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment")
        ->assertOk();

    expect($second->json('data'))->toBe($first->json('data'))
        ->and(Shipment::query()->where('order_id', $context['order']->id)->count())->toBe(1);
    Http::assertSentCount(2);
});

test('pickup and incomplete delivery orders are rejected before GHN is called', function (): void {
    $pickup = createGhnShipmentContext('pickup');
    $delivery = createGhnShipmentContext();
    $delivery['order']->update(['recipient_phone' => null]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/orders/{$pickup['order']->id}/shipment")
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.fulfillment_method.0',
            'Chỉ đơn hàng giao tận nơi mới có thể tạo vận đơn',
        );
    $this->postJson("/api/v1/admin/orders/{$delivery['order']->id}/shipment")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.shipping.0', 'Đơn hàng chưa có đầy đủ thông tin giao hàng');

    Http::assertNothingSent();
    $this->assertDatabaseCount('shipments', 0);
});

test('GHN failure leaves no shipment record', function (): void {
    $context = createGhnShipmentContext();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/available-services')) {
            return Http::response([
                'code' => 200,
                'data' => [[
                    'service_id' => 53320,
                    'short_name' => 'Light',
                    'service_type_id' => 2,
                ]],
            ]);
        }

        return Http::response(['code' => 500, 'data' => []], 500);
    });
    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.shipping.0', 'Không thể tạo vận đơn GHN lúc này');

    $this->assertDatabaseCount('shipments', 0);
});

test('admin shipment endpoint enforces authentication role and branch scope', function (): void {
    $own = createGhnShipmentContext();
    $other = createGhnShipmentContext();
    $path = "/api/v1/admin/orders/{$own['order']->id}/shipment";

    $this->postJson($path)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))
        ->postJson($path)
        ->assertForbidden();

    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $own['branch']->id,
    ]);
    $this->actingAs($manager)
        ->postJson("/api/v1/admin/orders/{$other['order']->id}/shipment")
        ->assertNotFound();

    Http::assertNothingSent();
    $this->assertDatabaseCount('shipments', 0);
});

test('shipment persistence failure rolls back the database transaction', function (): void {
    $context = createGhnShipmentContext();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    fakeSuccessfulGhnShipment('GHN-ROLLBACK');
    $event = 'eloquent.creating: '.Shipment::class;
    Event::listen($event, static function (): never {
        throw new RuntimeException('Simulated shipment persistence failure');
    });
    $this->actingAs($admin);
    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->postJson("/api/v1/admin/orders/{$context['order']->id}/shipment"))
            ->toThrow(RuntimeException::class, 'Simulated shipment persistence failure');
    } finally {
        Event::forget($event);
    }

    $this->assertDatabaseCount('shipments', 0);
    expect($context['order']->refresh()->status)->toBe(OrderStatus::Confirmed);
});
