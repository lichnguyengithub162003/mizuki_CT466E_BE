<?php

use App\Enums\BranchType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createReviewReadProduct(array $attributes = []): Product
{
    $suffix = Str::lower(Str::random(10));
    $category = Category::query()->create([
        'name' => "Review category {$suffix}",
        'slug' => "review-category-{$suffix}",
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => "Review brand {$suffix}",
        'slug' => "review-brand-{$suffix}",
        'is_active' => true,
    ]);

    return Product::query()->create(array_merge([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => "Review product {$suffix}",
        'slug' => "review-product-{$suffix}",
        'is_active' => true,
    ], $attributes));
}

function createReviewReadVariant(Product $product): ProductVariant
{
    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'REVIEW-'.Str::upper(Str::random(12)),
        'price' => 100_000,
        'weight' => 500,
        'is_active' => true,
    ]);
}

function createReadReview(
    Product $product,
    int $rating,
    ?CarbonInterface $createdAt = null,
    array $attributes = [],
): Review {
    $review = Review::query()->create(array_merge([
        'user_id' => User::factory()->create()->id,
        'product_id' => $product->id,
        'rating' => $rating,
        'title' => "Rating {$rating}",
        'comment' => "Review content {$rating}",
        'is_visible' => true,
    ], $attributes));

    if ($createdAt !== null) {
        $review->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }

    return $review;
}

function attachDeliveredPurchase(Review $review, ProductVariant $variant): OrderItem
{
    $branch = Branch::query()->create([
        'code' => 'RV'.Str::upper(Str::random(8)),
        'name' => 'Review branch',
        'branch_type' => BranchType::Store,
        'phone' => '0292000000',
        'address' => 'Cần Thơ',
        'province_code' => '92',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '22001',
        'is_active' => true,
    ]);
    $order = Order::query()->create([
        'order_number' => 'RV-'.Str::upper(Str::random(12)),
        'user_id' => $review->user_id,
        'branch_id' => $branch->id,
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Delivered,
        'subtotal' => 100_000,
        'total_amount' => 100_000,
    ]);
    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => $review->product->name,
        'variant_name' => $variant->name,
        'sku' => $variant->sku,
        'unit_price' => 100_000,
        'quantity' => 1,
        'line_total' => 100_000,
    ]);
    $review->update(['order_item_id' => $item->id, 'product_variant_id' => $variant->id]);

    return $item;
}

test('review endpoint returns persisted summary without fabricating external review records', function (): void {
    $product = createReviewReadProduct([
        'external_rating' => 4.95,
        'external_review_count' => 999,
    ]);

    foreach ([5, 5, 4, 3, 2, 1] as $rating) {
        createReadReview($product, $rating);
    }
    createReadReview($product, 5, attributes: ['is_visible' => false]);

    $this->getJson("/api/v1/products/{$product->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Lấy đánh giá sản phẩm thành công!')
        ->assertJsonPath('data.summary.average_rating', 3.3)
        ->assertJsonPath('data.summary.total_reviews', 6)
        ->assertJsonPath('data.summary.rating_distribution.5', 2)
        ->assertJsonPath('data.summary.rating_distribution.4', 1)
        ->assertJsonPath('data.summary.rating_distribution.3', 1)
        ->assertJsonPath('data.summary.rating_distribution.2', 1)
        ->assertJsonPath('data.summary.rating_distribution.1', 1)
        ->assertJsonPath('data.summary.reviews_with_images_count', 0)
        ->assertJsonPath('data.summary.verified_purchase_reviews_count', 0)
        ->assertJsonCount(6, 'data.reviews');
});

test('zero-review product returns zeroed persisted summary and empty list', function (): void {
    $product = createReviewReadProduct([
        'external_rating' => 4.8,
        'external_review_count' => 1434,
    ]);

    $this->getJson("/api/v1/products/{$product->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('data.summary.average_rating', 0)
        ->assertJsonPath('data.summary.total_reviews', 0)
        ->assertJsonPath('data.summary.rating_distribution.5', 0)
        ->assertJsonPath('data.summary.rating_distribution.4', 0)
        ->assertJsonPath('data.summary.rating_distribution.3', 0)
        ->assertJsonPath('data.summary.rating_distribution.2', 0)
        ->assertJsonPath('data.summary.rating_distribution.1', 0)
        ->assertJsonPath('data.summary.reviews_with_images_count', 0)
        ->assertJsonPath('data.summary.verified_purchase_reviews_count', 0)
        ->assertJsonPath('data.reviews', [])
        ->assertJsonPath('meta.pagination.total', 0);
});

test('review filters support exact rating images and verified purchase combinations', function (): void {
    $product = createReviewReadProduct();
    $variant = createReviewReadVariant($product);
    $verifiedFive = createReadReview($product, 5);
    attachDeliveredPurchase($verifiedFive, $variant);
    createReadReview($product, 5);
    createReadReview($product, 4);

    $this->getJson("/api/v1/products/{$product->slug}/reviews?rating=5")
        ->assertOk()
        ->assertJsonCount(2, 'data.reviews');

    $this->getJson("/api/v1/products/{$product->slug}/reviews?verified_purchase=true&rating=5")
        ->assertOk()
        ->assertJsonCount(1, 'data.reviews')
        ->assertJsonPath('data.reviews.0.id', $verifiedFive->id)
        ->assertJsonPath('data.reviews.0.verified_purchase', true);

    $this->getJson("/api/v1/products/{$product->slug}/reviews?verified_purchase=false")
        ->assertOk()
        ->assertJsonCount(2, 'data.reviews');

    $this->getJson("/api/v1/products/{$product->slug}/reviews?has_images=true")
        ->assertOk()
        ->assertJsonPath('data.reviews', [])
        ->assertJsonPath('meta.pagination.total', 0);

    $this->getJson("/api/v1/products/{$product->slug}/reviews?has_images=false")
        ->assertOk()
        ->assertJsonCount(3, 'data.reviews');
});

test('review query validation rejects unsupported values', function (string $query, string $field): void {
    $product = createReviewReadProduct();

    $this->getJson("/api/v1/products/{$product->slug}/reviews?{$query}")
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['data' => ['errors' => [$field]]]);
})->with([
    'rating above five' => ['rating=6', 'rating'],
    'invalid images boolean' => ['has_images=maybe', 'has_images'],
    'invalid verified boolean' => ['verified_purchase=maybe', 'verified_purchase'],
    'invalid sort' => ['sort=oldest', 'sort'],
    'per page above maximum' => ['per_page=51', 'per_page'],
    'invalid page' => ['page=0', 'page'],
]);

test('reviews are newest first and pagination metadata and second page are correct', function (): void {
    $product = createReviewReadProduct();
    $oldest = createReadReview($product, 3, now()->subDays(3));
    $middle = createReadReview($product, 4, now()->subDays(2));
    $newest = createReadReview($product, 5, now()->subDay());

    $this->getJson("/api/v1/products/{$product->slug}/reviews?sort=newest&per_page=2")
        ->assertOk()
        ->assertJsonPath('data.reviews.0.id', $newest->id)
        ->assertJsonPath('data.reviews.1.id', $middle->id)
        ->assertJsonPath('meta.pagination.current_page', 1)
        ->assertJsonPath('meta.pagination.per_page', 2)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.pagination.last_page', 2);

    $this->getJson("/api/v1/products/{$product->slug}/reviews?sort=newest&per_page=2&page=2")
        ->assertOk()
        ->assertJsonCount(1, 'data.reviews')
        ->assertJsonPath('data.reviews.0.id', $oldest->id)
        ->assertJsonPath('meta.pagination.current_page', 2);
});

test('review item exposes safe UI fields and derived verified purchase only', function (): void {
    $product = createReviewReadProduct();
    $variant = createReviewReadVariant($product);
    $user = User::factory()->create([
        'name' => 'Mizuki Customer',
        'avatar' => 'avatars/customer.jpg',
        'phone' => '0901234567',
    ]);
    $review = createReadReview($product, 5, now(), [
        'user_id' => $user->id,
        'title' => 'Rất tốt',
        'comment' => 'Sản phẩm phù hợp với da của tôi.',
    ]);
    attachDeliveredPurchase($review, $variant);

    $response = $this->getJson("/api/v1/products/{$product->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('data.reviews.0.customer.id', $user->id)
        ->assertJsonPath('data.reviews.0.customer.display_name', 'Mizuki Customer')
        ->assertJsonPath('data.reviews.0.customer.avatar_url', 'avatars/customer.jpg')
        ->assertJsonPath('data.reviews.0.rating', 5)
        ->assertJsonPath('data.reviews.0.title', 'Rất tốt')
        ->assertJsonPath('data.reviews.0.content', 'Sản phẩm phù hợp với da của tôi.')
        ->assertJsonPath('data.reviews.0.verified_purchase', true)
        ->assertJsonPath('data.reviews.0.images', [])
        ->assertJsonPath('data.reviews.0.helpful_count', 0)
        ->assertJsonPath('data.reviews.0.mizuki_response', null)
        ->assertJsonStructure(['data' => ['reviews' => [['reviewed_at']]]]);

    expect($response->json('data.reviews.0'))
        ->not->toHaveKeys(['user_id', 'order_item_id', 'order_id', 'email', 'phone'])
        ->and($response->json('data.reviews.0.customer'))
        ->not->toHaveKeys(['email', 'phone']);
});

test('imported reviews expose source customer metadata images verification and normalized Mizuki response', function (): void {
    $product = createReviewReadProduct([
        'external_rating' => 4.9,
        'external_review_count' => 999,
    ]);
    $imported = Review::query()->create([
        'source' => 'hasaki',
        'source_key' => hash('sha256', 'imported-review'),
        'source_author_name' => 'Khách nguồn',
        'source_verified_purchase' => true,
        'source_date' => '2025-10-20, 13:19',
        'variant_purchased' => '500ml',
        'images' => ['https://example.test/review.jpg'],
        'mizuki_response_content' => 'Mizuki cảm ơn bạn đã mua sắm tại Mizuki.',
        'product_id' => $product->id,
        'rating' => 5,
        'title' => 'Rất hài lòng',
        'comment' => 'Đánh giá thực tế từ nguồn.',
        'is_visible' => true,
    ]);
    $imported->forceFill([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ])->saveQuietly();
    createReadReview($product, 3, now()->subDays(2));

    $response = $this->getJson("/api/v1/products/{$product->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('data.summary.average_rating', 4)
        ->assertJsonPath('data.summary.total_reviews', 2)
        ->assertJsonPath('data.summary.rating_distribution.5', 1)
        ->assertJsonPath('data.summary.rating_distribution.3', 1)
        ->assertJsonPath('data.summary.reviews_with_images_count', 1)
        ->assertJsonPath('data.summary.verified_purchase_reviews_count', 1)
        ->assertJsonPath('data.reviews.0.id', $imported->id)
        ->assertJsonPath('data.reviews.0.customer.id', null)
        ->assertJsonPath('data.reviews.0.customer.display_name', 'Khách nguồn')
        ->assertJsonPath('data.reviews.0.customer.avatar_url', null)
        ->assertJsonPath('data.reviews.0.rating', 5)
        ->assertJsonPath('data.reviews.0.title', 'Rất hài lòng')
        ->assertJsonPath('data.reviews.0.content', 'Đánh giá thực tế từ nguồn.')
        ->assertJsonPath('data.reviews.0.verified_purchase', true)
        ->assertJsonPath('data.reviews.0.images.0', 'https://example.test/review.jpg')
        ->assertJsonPath('data.reviews.0.helpful_count', 0)
        ->assertJsonPath('data.reviews.0.mizuki_response.author', 'Mizuki')
        ->assertJsonPath(
            'data.reviews.0.mizuki_response.content',
            'Mizuki cảm ơn bạn đã mua sắm tại Mizuki.',
        );

    expect($response->json('data.reviews.0.mizuki_response.content'))->not->toContain('Hasaki')
        ->and($response->json('data.reviews.0'))
        ->not->toHaveKeys(['source', 'source_key', 'source_date', 'user_id', 'order_item_id']);

    foreach (['rating=5', 'has_images=true', 'verified_purchase=true'] as $filter) {
        $this->getJson("/api/v1/products/{$product->slug}/reviews?{$filter}")
            ->assertOk()
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.reviews.0.id', $imported->id);
    }
});

test('verified purchase requires delivered matching-user matching-product order item', function (): void {
    $product = createReviewReadProduct();
    $otherProduct = createReviewReadProduct();
    $otherVariant = createReviewReadVariant($otherProduct);
    $review = createReadReview($product, 5);
    attachDeliveredPurchase($review, $otherVariant);

    $this->getJson("/api/v1/products/{$product->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('data.reviews.0.verified_purchase', false)
        ->assertJsonPath('data.summary.verified_purchase_reviews_count', 0);
});

test('review endpoint returns not found for missing and inactive products', function (): void {
    $inactive = createReviewReadProduct(['is_active' => false]);

    $this->getJson('/api/v1/products/missing-product/reviews')
        ->assertNotFound()
        ->assertJsonPath('message', 'Không tìm thấy sản phẩm');
    $this->getJson("/api/v1/products/{$inactive->slug}/reviews")
        ->assertNotFound()
        ->assertJsonPath('message', 'Không tìm thấy sản phẩm');
});

test('review endpoint query count stays constant between one and ten reviews', function (): void {
    $product = createReviewReadProduct();
    createReadReview($product, 5);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson("/api/v1/products/{$product->slug}/reviews?per_page=20")->assertOk();
    $oneReviewQueries = count(DB::getQueryLog());

    foreach (range(2, 10) as $rating) {
        createReadReview($product, ($rating % 5) + 1);
    }

    DB::flushQueryLog();
    $this->getJson("/api/v1/products/{$product->slug}/reviews?per_page=20")
        ->assertOk()
        ->assertJsonCount(10, 'data.reviews');
    $tenReviewQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($oneReviewQueries)->toBe(7)
        ->and($tenReviewQueries)->toBe(7);
});
