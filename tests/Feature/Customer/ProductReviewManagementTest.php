<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{order: Order, item: OrderItem, product: Product, variant: ProductVariant}
 */
function createVerifiedReviewPurchase(
    ?User $owner,
    OrderStatus $status = OrderStatus::Delivered,
    string $channel = 'online',
    ?ProductVariant $variant = null,
): array {
    $token = Str::upper(Str::random(10));
    $branch = Branch::query()->create([
        'code' => 'RV'.$token,
        'name' => 'Mizuki Review '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);

    if ($variant === null) {
        $category = Category::query()->create([
            'name' => 'Review '.$token,
            'slug' => 'review-category-'.Str::lower($token),
            'is_active' => true,
        ]);
        $brand = Brand::query()->create([
            'name' => 'Review '.$token,
            'slug' => 'review-brand-'.Str::lower($token),
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Sản phẩm review '.$token,
            'slug' => 'review-product-'.Str::lower($token),
            'is_active' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'REVIEW-'.$token,
            'price' => 100_000,
            'weight' => 500,
            'is_active' => true,
        ]);
    } else {
        $product = $variant->product()->firstOrFail();
    }

    $order = Order::query()->create([
        'order_number' => 'RV-'.$token,
        'user_id' => $owner?->id,
        'branch_id' => $branch->id,
        'channel' => $channel,
        'fulfillment_method' => $channel === 'counter' ? 'pickup' : 'shipping',
        'payment_method' => PaymentMethod::Cash,
        'status' => $status,
        'subtotal' => 100_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 100_000,
        'placed_at' => now(),
    ]);
    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => $product->name,
        'variant_name' => $variant->name,
        'sku' => $variant->sku,
        'unit_price' => 100_000,
        'quantity' => 1,
        'line_total' => 100_000,
    ]);

    return compact('order', 'item', 'product', 'variant');
}

test('customer reviews a delivered online purchase using backend-derived ownership', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => 5,
            'title' => 'Rất tốt',
            'comment' => 'Sản phẩm phù hợp với tôi.',
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.product.id', $purchase['product']->id)
        ->assertJsonPath('data.product_variant.id', $purchase['variant']->id)
        ->assertJsonPath('data.order_item_id', $purchase['item']->id)
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.verified_purchase', true)
        ->assertJsonPath('message', 'Đánh giá sản phẩm thành công!');

    $this->assertDatabaseHas('reviews', [
        'user_id' => $customer->id,
        'product_id' => $purchase['product']->id,
        'product_variant_id' => $purchase['variant']->id,
        'order_item_id' => $purchase['item']->id,
        'rating' => 5,
        'source' => null,
    ]);

    $this->getJson("/api/v1/products/{$purchase['product']->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('data.reviews.0.verified_purchase', true);
});

test('linked customer reviews a confirmed counter purchase', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer, OrderStatus::Confirmed, 'counter');

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => 4,
        ])
        ->assertCreated()
        ->assertJsonPath('data.verified_purchase', true);

    $this->getJson("/api/v1/products/{$purchase['product']->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('data.reviews.0.verified_purchase', true);
});

test('customer cannot review another customer order item', function (): void {
    $owner = User::factory()->create(['role' => UserRole::Customer]);
    $attacker = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($owner);

    $this->actingAs($attacker)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => 5,
        ])
        ->assertNotFound()
        ->assertJsonPath('message', 'Không tìm thấy sản phẩm đã mua');

    $this->assertDatabaseCount('reviews', 0);
});

test('ineligible order statuses cannot be reviewed', function (
    OrderStatus $status,
    string $channel,
): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer, $status, $channel);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => 5,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.order_item_id.0', 'Đơn hàng chưa đủ điều kiện đánh giá');

    $this->assertDatabaseCount('reviews', 0);
})->with([
    'pending online' => [OrderStatus::Pending, 'online'],
    'confirmed online' => [OrderStatus::Confirmed, 'online'],
    'processing online' => [OrderStatus::Processing, 'online'],
    'shipping online' => [OrderStatus::Shipping, 'online'],
    'cancelled online' => [OrderStatus::Cancelled, 'online'],
    'refund requested online' => [OrderStatus::RefundRequested, 'online'],
    'refunded online' => [OrderStatus::Refunded, 'online'],
    'delivered counter' => [OrderStatus::Delivered, 'counter'],
]);

test('guest counter order is not eligible for authenticated customer review', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase(null, OrderStatus::Confirmed, 'counter');

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => 5,
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('reviews', 0);
});

test('customer cannot forge review ownership and product identity fields', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer);
    $other = createVerifiedReviewPurchase($customer);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => 5,
            'user_id' => User::factory()->create()->id,
            'product_id' => $other['product']->id,
            'product_variant_id' => $other['variant']->id,
            'order_id' => $other['order']->id,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => [
            'user_id', 'product_id', 'product_variant_id', 'order_id',
        ]]]);

    $this->assertDatabaseCount('reviews', 0);
});

test('duplicate review for the same product is rejected cleanly', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $first = createVerifiedReviewPurchase($customer);
    $second = createVerifiedReviewPurchase($customer, variant: $first['variant']);
    $this->actingAs($customer);

    $this->postJson('/api/v1/customer/reviews', [
        'order_item_id' => $first['item']->id,
        'rating' => 5,
    ])->assertCreated();

    $this->postJson('/api/v1/customer/reviews', [
        'order_item_id' => $second['item']->id,
        'rating' => 4,
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.order_item_id.0', 'Bạn đã đánh giá sản phẩm này');

    $this->assertDatabaseCount('reviews', 1);
});

test('order item already linked to another review cannot be reused', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer);
    Review::query()->create([
        'user_id' => User::factory()->create()->id,
        'product_id' => $purchase['product']->id,
        'product_variant_id' => $purchase['variant']->id,
        'order_item_id' => $purchase['item']->id,
        'rating' => 3,
        'is_visible' => true,
    ]);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => 5,
        ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.order_item_id.0',
            'Sản phẩm trong đơn hàng này đã được đánh giá',
        );

    $this->assertDatabaseCount('reviews', 1);
});

test('rating must be an integer from one to five', function (mixed $rating): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/reviews', [
            'order_item_id' => $purchase['item']->id,
            'rating' => $rating,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['rating']]]);
})->with([
    'below minimum' => 0,
    'above maximum' => 6,
    'not integer' => 2.5,
]);

test('customer updates only rating title and comment on their own review', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer);
    $review = Review::query()->create([
        'user_id' => $customer->id,
        'product_id' => $purchase['product']->id,
        'product_variant_id' => $purchase['variant']->id,
        'order_item_id' => $purchase['item']->id,
        'rating' => 3,
        'title' => 'Ban đầu',
        'comment' => null,
        'is_visible' => true,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/v1/customer/reviews/{$review->id}", [
            'rating' => 5,
            'title' => 'Đã cập nhật',
            'comment' => 'Trải nghiệm tốt hơn mong đợi.',
        ])
        ->assertOk()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.title', 'Đã cập nhật')
        ->assertJsonPath('data.comment', 'Trải nghiệm tốt hơn mong đợi.')
        ->assertJsonPath('message', 'Cập nhật đánh giá thành công!');

    $this->assertDatabaseHas('reviews', [
        'id' => $review->id,
        'user_id' => $customer->id,
        'product_id' => $purchase['product']->id,
        'order_item_id' => $purchase['item']->id,
        'rating' => 5,
    ]);
});

test('customer cannot update another customer review', function (): void {
    $owner = User::factory()->create(['role' => UserRole::Customer]);
    $attacker = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($owner);
    $review = Review::query()->create([
        'user_id' => $owner->id,
        'product_id' => $purchase['product']->id,
        'product_variant_id' => $purchase['variant']->id,
        'order_item_id' => $purchase['item']->id,
        'rating' => 5,
        'is_visible' => true,
    ]);

    $this->actingAs($attacker)
        ->patchJson("/api/v1/customer/reviews/{$review->id}", ['rating' => 1])
        ->assertForbidden();

    expect($review->refresh()->rating)->toBe(5);
});

test('imported source review cannot be updated by a customer', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer);
    $review = Review::query()->create([
        'source' => 'hasaki',
        'source_key' => hash('sha256', Str::random()),
        'source_author_name' => 'Khách nguồn',
        'product_id' => $purchase['product']->id,
        'rating' => 5,
        'is_visible' => true,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/v1/customer/reviews/{$review->id}", ['rating' => 1])
        ->assertForbidden();

    expect($review->refresh()->rating)->toBe(5);
});

test('guest and non customer roles cannot create product reviews', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $purchase = createVerifiedReviewPurchase($customer);
    $payload = ['order_item_id' => $purchase['item']->id, 'rating' => 5];

    $this->postJson('/api/v1/customer/reviews', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
        ->postJson('/api/v1/customer/reviews', $payload)
        ->assertForbidden();

    $this->assertDatabaseCount('reviews', 0);
});
