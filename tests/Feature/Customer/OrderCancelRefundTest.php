<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{user: User, other_user: User, branch: Branch, order: Order, inventory: BranchInventory} */
function createOrderCancelRefundContext(OrderStatus $status = OrderStatus::Pending): array
{
    $token = Str::upper(Str::random(8));
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $otherUser = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'CR'.$token, 'name' => 'Mizuki Cancel '.$token,
        'phone' => '02923888888', 'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT', 'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012', 'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Cancel '.$token, 'slug' => 'cancel-category-'.strtolower($token), 'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Cancel Brand '.$token, 'slug' => 'cancel-brand-'.strtolower($token), 'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id, 'brand_id' => $brand->id,
        'name' => 'Cancel Product '.$token, 'slug' => 'cancel-product-'.strtolower($token),
        'is_active' => true, 'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id, 'name' => '50 ml', 'sku' => 'CANCEL-'.$token,
        'attributes' => ['capacity' => '50 ml'], 'price' => 150_000,
        'weight' => 50, 'sort_order' => 0, 'is_active' => true,
    ]);
    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id, 'product_variant_id' => $variant->id,
        'quantity' => 10, 'reserved_quantity' => 3, 'reorder_level' => 2,
    ]);
    $order = Order::query()->create([
        'order_number' => 'MZ-'.Str::upper(Str::random(12)),
        'user_id' => $user->id, 'branch_id' => $branch->id,
        'channel' => 'online', 'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::Cash, 'status' => $status,
        'subtotal' => 300_000, 'discount_amount' => 0,
        'shipping_fee' => 0, 'total_amount' => 300_000, 'placed_at' => now(),
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id, 'product_name' => $product->name,
        'variant_name' => $variant->name, 'sku' => $variant->sku,
        'variant_attributes' => $variant->attributes, 'unit_price' => 150_000,
        'quantity' => 2, 'line_total' => 300_000,
    ]);

    return [
        'user' => $user, 'other_user' => $otherUser, 'branch' => $branch,
        'order' => $order, 'inventory' => $inventory,
    ];
}

test('customer can cancel an eligible order and reserved stock is released', function (): void {
    $context = createOrderCancelRefundContext(OrderStatus::Confirmed);
    Event::fake([OrderStatusUpdated::class]);
    $this->actingAs($context['user']);

    $this->postJson("/api/v1/customer/orders/{$context['order']->id}/cancel", [
        'reason_type' => 'changed_mind',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation.reason_type', 'changed_mind')
        ->assertJsonPath('data.cancellation.reason', 'Thay đổi nhu cầu');

    expect($context['inventory']->refresh()->reserved_quantity)->toBe(1)
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Cancelled);
    Event::assertDispatched(OrderStatusUpdated::class);
});

test('customer cannot cancel an order in a non cancellable status', function (): void {
    $context = createOrderCancelRefundContext(OrderStatus::Processing);
    $this->actingAs($context['user']);

    $this->postJson("/api/v1/customer/orders/{$context['order']->id}/cancel", [
        'reason_type' => 'changed_mind',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.status.0', 'Đơn hàng ở trạng thái hiện tại không thể hủy');

    expect($context['inventory']->refresh()->reserved_quantity)->toBe(3);
});

test('customer cannot cancel another customers order', function (): void {
    $context = createOrderCancelRefundContext();
    $this->actingAs($context['other_user']);

    $this->postJson("/api/v1/customer/orders/{$context['order']->id}/cancel", [
        'reason_type' => 'other', 'reason' => 'Không phải đơn của tôi',
    ])->assertNotFound()->assertJsonPath('message', 'Không tìm thấy đơn hàng');
});

test('customer can request a refund with multiple valid evidence files', function (): void {
    Storage::fake('public');
    $context = createOrderCancelRefundContext(OrderStatus::Delivered);
    $this->actingAs($context['user']);

    $response = $this->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        [
            'reason_type' => 'product_damaged',
            'reason' => 'Hộp bị vỡ khi nhận hàng',
            'evidence' => [
                UploadedFile::fake()->create('front.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('detail.png', 100, 'image/png'),
            ],
        ],
        ['Accept' => 'application/json'],
    )
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.status_label', 'Chờ duyệt')
        ->assertJsonPath('data.reason_type', 'product_damaged')
        ->assertJsonCount(2, 'data.evidence_paths');

    $paths = $response->json('data.evidence_paths');
    Storage::disk('public')->assertExists($paths);
    $this->assertDatabaseHas('refunds', [
        'order_id' => $context['order']->id,
        'user_id' => $context['user']->id,
        'status' => 'requested',
        'requested_amount' => 300_000,
    ]);
    expect($context['order']->refresh()->status)->toBe(OrderStatus::Delivered);

    $this->getJson("/api/v1/customer/orders/{$context['order']->id}")
        ->assertOk()
        ->assertJsonPath('data.refund.id', $response->json('data.id'))
        ->assertJsonPath('data.refund.status', 'requested')
        ->assertJsonPath('data.refund.status_label', 'Chờ duyệt')
        ->assertJsonPath('data.refund.requested_amount', 300_000);
});

test('customer refund status labels remain readable after review and payout', function (): void {
    $context = createOrderCancelRefundContext(OrderStatus::Delivered);
    $refund = Refund::query()->create([
        'refund_number' => 'RF-'.Str::upper(Str::random(12)),
        'order_id' => $context['order']->id,
        'user_id' => $context['user']->id,
        'status' => 'refunded',
        'requested_amount' => 300_000,
        'approved_amount' => 250_000,
        'reason_type' => 'product_damaged',
        'reason' => 'Sản phẩm bị hư hỏng',
        'evidence_paths' => ['refund-evidence/proof.jpg'],
        'review_note' => 'Duyệt một phần',
        'reviewed_at' => now(),
        'refunded_at' => now(),
    ]);
    $this->actingAs($context['user']);

    $this->getJson("/api/v1/customer/orders/{$context['order']->id}")
        ->assertOk()
        ->assertJsonPath('data.refund.id', $refund->id)
        ->assertJsonPath('data.refund.status', 'refunded')
        ->assertJsonPath('data.refund.status_label', 'Đã hoàn tiền')
        ->assertJsonPath('data.refund.approved_amount', 250_000)
        ->assertJsonPath('data.refund.review_note', 'Duyệt một phần');
});

test('customer cannot request a second refund for the same order', function (): void {
    Storage::fake('public');
    $context = createOrderCancelRefundContext(OrderStatus::Delivered);
    Refund::query()->create([
        'refund_number' => 'RF-'.Str::upper(Str::random(12)),
        'order_id' => $context['order']->id,
        'user_id' => $context['user']->id,
        'status' => 'requested',
        'requested_amount' => 300_000,
        'reason_type' => 'product_quality',
        'reason' => 'Chất lượng không phù hợp',
        'evidence_paths' => ['refund-evidence/original.jpg'],
    ]);
    $this->actingAs($context['user']);

    $this->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        [
            'reason_type' => 'product_damaged',
            'evidence' => [UploadedFile::fake()->create('duplicate.jpg', 100, 'image/jpeg')],
        ],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()
        ->assertJsonPath('data.errors.refund.0', 'Đơn hàng đã có yêu cầu hoàn tiền');

    $this->assertDatabaseCount('refunds', 1);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('refund request requires at least one evidence file', function (): void {
    Storage::fake('public');
    $context = createOrderCancelRefundContext(OrderStatus::Delivered);
    $this->actingAs($context['user']);

    $this->postJson("/api/v1/customer/orders/{$context['order']->id}/refund", [
        'reason_type' => 'product_quality',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.evidence.0', 'Vui lòng cung cấp ít nhất một file bằng chứng');
});

test('refund request rejects unsupported evidence formats', function (): void {
    Storage::fake('public');
    $context = createOrderCancelRefundContext(OrderStatus::Delivered);
    $this->actingAs($context['user']);

    $this->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        [
            'reason_type' => 'wrong_product',
            'evidence' => [UploadedFile::fake()->create('proof.txt', 10, 'text/plain')],
        ],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()
        ->assertJsonFragment(['File bằng chứng chỉ hỗ trợ JPG, JPEG, PNG hoặc MP4']);
});

test('refund request is only allowed for delivered orders', function (): void {
    Storage::fake('public');
    $context = createOrderCancelRefundContext(OrderStatus::Shipping);
    $this->actingAs($context['user']);

    $this->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        [
            'reason_type' => 'shipping_delay',
            'evidence' => [UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg')],
        ],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()
        ->assertJsonPath('data.errors.refund.0', 'Chỉ có thể yêu cầu hoàn tiền cho đơn hàng đã giao');
});

test('guest cannot cancel orders or request refunds', function (): void {
    $this->postJson('/api/v1/customer/orders/1/cancel', ['reason_type' => 'changed_mind'])
        ->assertUnauthorized();
    $this->postJson('/api/v1/customer/orders/1/refund', [])->assertUnauthorized();
});
