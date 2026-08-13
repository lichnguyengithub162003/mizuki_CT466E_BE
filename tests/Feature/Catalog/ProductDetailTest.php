<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\BrandFollow;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createDetailCategory(string $name, ?int $parentId = null): Category
{
    return Category::query()->create([
        'parent_id' => $parentId,
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'is_active' => true,
    ]);
}

function createDetailBrand(string $name): Brand
{
    return Brand::query()->create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'is_active' => true,
    ]);
}

function createDetailProduct(
    Category $category,
    Brand $brand,
    string $name,
    bool $isActive = true,
): Product {
    return Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'short_description' => 'Mô tả ngắn sản phẩm.',
        'description' => 'Mô tả chi tiết sản phẩm.',
        'ingredients' => 'Thành phần thử nghiệm.',
        'usage_instructions' => 'Sử dụng mỗi ngày.',
        'origin_country' => 'Nhật Bản',
        'is_active' => $isActive,
        'is_featured' => false,
    ]);
}

function createDetailVariant(
    Product $product,
    string $name,
    int $price,
    ?int $salePrice,
    int $sortOrder,
): ProductVariant {
    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => $name,
        'sku' => 'DETAIL-'.Str::upper(Str::random(10)),
        'attributes' => ['capacity' => $name],
        'price' => $price,
        'sale_price' => $salePrice,
        'weight' => 100,
        'sort_order' => $sortOrder,
        'is_active' => true,
    ]);
}

function createDetailBranch(string $code, string $name): Branch
{
    return Branch::query()->create([
        'code' => $code,
        'name' => $name,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
}

test('product detail returns images variants and available branch inventories', function (): void {
    $parent = createDetailCategory('Chăm sóc da');
    $category = createDetailCategory('Serum', $parent->id);
    $brand = createDetailBrand('Mizuki Lab');
    $product = createDetailProduct($category, $brand, 'Serum phục hồi da');
    $variant = createDetailVariant($product, '50 ml', 200_000, 150_000, 0);
    createDetailVariant($product, '100 ml', 320_000, null, 1);

    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => 'products/secondary.jpg',
        'alt_text' => 'Ảnh phụ',
        'sort_order' => 0,
        'is_primary' => false,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => 'products/primary.jpg',
        'alt_text' => 'Ảnh chính',
        'sort_order' => 10,
        'is_primary' => true,
    ]);

    $availableBranch = createDetailBranch('CT01', 'Mizuki Ninh Kiều');
    $unavailableBranch = createDetailBranch('CT02', 'Mizuki Cái Răng');

    BranchInventory::query()->create([
        'branch_id' => $availableBranch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 10,
        'reserved_quantity' => 3,
        'reorder_level' => 2,
    ]);
    BranchInventory::query()->create([
        'branch_id' => $unavailableBranch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 5,
        'reserved_quantity' => 5,
        'reorder_level' => 2,
    ]);

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Lấy chi tiết sản phẩm thành công!')
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.category.id', $category->id)
        ->assertJsonPath('data.category.parent_id', $parent->id)
        ->assertJsonPath('data.brand.id', $brand->id)
        ->assertJsonCount(2, 'data.images')
        ->assertJsonPath('data.images.0.image_url', 'products/primary.jpg')
        ->assertJsonPath('data.images.0.is_primary', true)
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.variants.0.attributes.capacity', '50 ml')
        ->assertJsonCount(1, 'data.variants.0.inventories')
        ->assertJsonPath('data.variants.0.inventories.0.branch_id', $availableBranch->id)
        ->assertJsonPath('data.variants.0.inventories.0.branch_name', 'Mizuki Ninh Kiều')
        ->assertJsonPath('data.variants.0.inventories.0.available_quantity', 7)
        ->assertJsonPath('data.variants.0.total_available_quantity', 7)
        ->assertJsonPath('data.variants.0.available', true)
        ->assertJsonPath('data.variants.1.total_available_quantity', 0)
        ->assertJsonPath('data.variants.1.available', false)
        ->assertJsonStructure([
            'success',
            'data' => [
                'id', 'name', 'slug', 'description', 'category', 'brand', 'images', 'variants',
            ],
            'message',
            'meta',
        ]);
});

test('product detail resolves source variant family navigation without changing sellable variants', function (): void {
    $category = createDetailCategory('Family navigation');
    $brand = createDetailBrand('Family Brand');
    $current = createDetailProduct($category, $brand, 'Current 100ml');
    $current->update([
        'source' => 'hasaki',
        'external_id' => '1735',
        'source_variant_groups' => [[
            'id' => 143,
            'code' => 'capacity',
            'label' => 'Dung TÃ­ch',
            'display_type' => null,
            'selected' => '100ml',
            'options' => [[
                'id' => 15,
                'label' => '100ml',
                'long_label' => null,
                'is_default' => true,
                'option_color' => null,
                'is_hot' => false,
                'image' => null,
                'products' => [
                    ['external_id' => '1735', 'source_sku' => 'SOURCE-1735', 'price' => 100000],
                    ['external_id' => '9740', 'source_sku' => 'SOURCE-9740', 'price' => 200000],
                    ['external_id' => '999999', 'source_sku' => null, 'price' => null],
                ],
            ]],
        ]],
    ]);
    $related = createDetailProduct($category, $brand, 'Related 500ml');
    $related->update(['source' => 'hasaki', 'external_id' => '9740']);
    $variant = createDetailVariant($current, '100 ml', 100_000, null, 0);

    $response = $this->getJson("/api/v1/products/{$current->slug}")
        ->assertOk()
        ->assertJsonCount(1, 'data.variant_groups')
        ->assertJsonCount(3, 'data.variant_groups.0.options.0.products')
        ->assertJsonPath('data.variant_groups.0.options.0.products.0.external_id', '1735')
        ->assertJsonPath('data.variant_groups.0.options.0.products.0.product_id', $current->id)
        ->assertJsonPath('data.variant_groups.0.options.0.products.0.slug', $current->slug)
        ->assertJsonPath('data.variant_groups.0.options.0.products.1.product_id', $related->id)
        ->assertJsonPath('data.variant_groups.0.options.0.products.1.slug', $related->slug)
        ->assertJsonPath('data.variant_groups.0.options.0.products.2.product_id', null)
        ->assertJsonPath('data.variant_groups.0.options.0.products.2.slug', null)
        ->assertJsonPath('data.variant_groups.0.options.0.products.2.name', null)
        ->assertJsonCount(1, 'data.variants')
        ->assertJsonPath('data.variants.0.id', $variant->id)
        ->assertJsonPath('data.variants.0.sku', $variant->sku);

    expect($response->json('data.variants.0.attributes'))->toBe(['capacity' => '100 ml']);
});

test('product detail returns 404 envelope when slug does not exist', function (): void {
    $this->getJson('/api/v1/products/khong-ton-tai')
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data', null)
        ->assertJsonPath('message', 'Không tìm thấy sản phẩm')
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});

test('product detail returns 404 envelope for inactive products', function (): void {
    $category = createDetailCategory('Sản phẩm ẩn');
    $brand = createDetailBrand('Hidden Lab');
    $product = createDetailProduct($category, $brand, 'Sản phẩm ngừng bán', false);

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Không tìm thấy sản phẩm');
});

test('product detail calculates effective prices with and without sale prices', function (): void {
    $category = createDetailCategory('Giá sản phẩm');
    $brand = createDetailBrand('Price Lab');
    $product = createDetailProduct($category, $brand, 'Sản phẩm kiểm tra giá');

    createDetailVariant($product, 'Đang giảm giá', 200_000, 150_000, 0);
    createDetailVariant($product, 'Không giảm giá', 300_000, null, 1);

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.price', 200_000)
        ->assertJsonPath('data.variants.0.sale_price', 150_000)
        ->assertJsonPath('data.variants.0.effective_price', 150_000)
        ->assertJsonPath('data.variants.1.price', 300_000)
        ->assertJsonPath('data.variants.1.sale_price', null)
        ->assertJsonPath('data.variants.1.effective_price', 300_000);
});

test('product detail returns complete weighted brand statistics from active products', function (): void {
    $category = createDetailCategory('Brand statistics');
    $brand = createDetailBrand('DHC');
    $brand->update(['logo_url' => 'brands/dhc.png', 'follower_count' => 2430]);
    $internal = createDetailProduct($category, $brand, 'Internal reviews');
    $internal->update(['external_rating' => 1.0, 'external_review_count' => 99]);
    $external = createDetailProduct($category, $brand, 'External reviews');
    $external->update(['external_rating' => 5.0, 'external_review_count' => 3]);
    $zeroReviews = createDetailProduct($category, $brand, 'Zero reviews');
    $zeroReviews->update(['external_rating' => 5.0, 'external_review_count' => 0]);
    $inactive = createDetailProduct($category, $brand, 'Inactive', false);
    $inactive->update(['external_rating' => 1.0, 'external_review_count' => 500]);
    $deleted = createDetailProduct($category, $brand, 'Deleted');
    $deleted->update(['external_rating' => 1.0, 'external_review_count' => 500]);
    $deleted->delete();

    foreach ([5, 3] as $rating) {
        Review::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $internal->id,
            'rating' => $rating,
            'is_visible' => true,
        ]);
    }

    $this->getJson("/api/v1/products/{$internal->slug}")
        ->assertOk()
        ->assertJsonPath('data.brand.id', $brand->id)
        ->assertJsonPath('data.brand.name', 'DHC')
        ->assertJsonPath('data.brand.slug', $brand->slug)
        ->assertJsonPath('data.brand.logo_url', 'brands/dhc.png')
        ->assertJsonPath('data.brand.active_product_count', 3)
        ->assertJsonPath('data.brand.average_rating', 4.6)
        ->assertJsonPath('data.brand.review_count', 5)
        ->assertJsonPath('data.brand.follower_count', 2430)
        ->assertJsonPath('data.brand.is_following', false);
});

test('product detail brand statistics return zeros when active products have no reviews', function (): void {
    $category = createDetailCategory('No reviews');
    $brand = createDetailBrand('No Review Brand');
    $product = createDetailProduct($category, $brand, 'Unreviewed product');

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.brand.active_product_count', 1)
        ->assertJsonPath('data.brand.average_rating', 0)
        ->assertJsonPath('data.brand.review_count', 0)
        ->assertJsonPath('data.brand.follower_count', 0);
});

test('product detail brand statistics add a constant number of queries', function (): void {
    $category = createDetailCategory('Query count');
    $brand = createDetailBrand('Efficient Brand');
    $product = createDetailProduct($category, $brand, 'Measured product');

    $countQueries = function () use ($product): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

        return $queries;
    };

    $baseline = $countQueries();

    foreach (range(1, 10) as $index) {
        $other = createDetailProduct($category, $brand, "Brand product {$index}");
        Review::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $other->id,
            'rating' => 5,
            'is_visible' => true,
        ]);
    }

    expect($countQueries() - $baseline)->toBe(0);
});

test('product detail resolves persisted brand follow state for customers and false for guests', function (): void {
    $category = createDetailCategory('Follow state');
    $brand = createDetailBrand('Follow State Brand');
    $product = createDetailProduct($category, $brand, 'Follow state product');
    $followingCustomer = User::factory()->create(['role' => UserRole::Customer]);
    $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);

    BrandFollow::query()->create([
        'user_id' => $followingCustomer->id,
        'brand_id' => $brand->id,
    ]);

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.brand.is_following', false);

    $this->actingAs($followingCustomer)
        ->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.brand.is_following', true);

    $this->actingAs($otherCustomer)
        ->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.brand.is_following', false);
});

test('product detail keeps brand follow state false for authenticated staff', function (): void {
    $category = createDetailCategory('Staff follow state');
    $brand = createDetailBrand('Staff Follow Brand');
    $product = createDetailProduct($category, $brand, 'Staff follow state product');
    $staff = User::factory()->create(['role' => UserRole::Cashier]);

    BrandFollow::query()->create([
        'user_id' => $staff->id,
        'brand_id' => $brand->id,
    ]);

    $this->actingAs($staff)
        ->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.brand.is_following', false);
});

test('authenticated customer product detail adds exactly one follow existence query', function (): void {
    $category = createDetailCategory('Follow query count');
    $brand = createDetailBrand('Follow Query Brand');
    $product = createDetailProduct($category, $brand, 'Follow query product');
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
    $guestQueries = count(DB::getQueryLog());

    $this->actingAs($customer);
    DB::flushQueryLog();
    $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
    $customerQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($customerQueries)->toBe($guestQueries + 1);
});

test('product detail returns ordered questions with nested ordered answers', function (): void {
    $category = createDetailCategory('Product questions');
    $brand = createDetailBrand('Question Brand');
    $product = createDetailProduct($category, $brand, 'Product with questions');
    $laterQuestion = ProductQuestion::query()->create([
        'product_id' => $product->id,
        'source' => 'hasaki',
        'external_key' => 'later-question',
        'author_name' => 'Second customer',
        'question' => 'Second question',
        'source_date' => 'unparsed date',
        'sort_order' => 1,
    ]);
    $firstQuestion = ProductQuestion::query()->create([
        'product_id' => $product->id,
        'source' => 'hasaki',
        'external_key' => 'first-question',
        'author_name' => 'First customer',
        'question' => 'First question',
        'asked_at' => '2026-06-13 20:11:00',
        'sort_order' => 0,
    ]);
    ProductQuestionAnswer::query()->create([
        'product_question_id' => $firstQuestion->id,
        'source' => 'hasaki',
        'external_key' => 'second-answer',
        'author_name' => 'Hasaki',
        'answer' => 'Second answer',
        'answered_at' => '2026-06-13 21:05:00',
        'sort_order' => 1,
    ]);
    ProductQuestionAnswer::query()->create([
        'product_question_id' => $firstQuestion->id,
        'source' => 'hasaki',
        'external_key' => 'first-answer',
        'author_name' => 'Hasaki',
        'answer' => 'First answer',
        'answered_at' => '2026-06-13 21:04:00',
        'sort_order' => 0,
    ]);

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonCount(2, 'data.questions_and_answers')
        ->assertJsonPath('data.questions_and_answers.0.id', $firstQuestion->id)
        ->assertJsonPath('data.questions_and_answers.0.author', 'First customer')
        ->assertJsonPath('data.questions_and_answers.0.question', 'First question')
        ->assertJsonPath('data.questions_and_answers.0.date', '2026-06-13, 20:11')
        ->assertJsonCount(2, 'data.questions_and_answers.0.answers')
        ->assertJsonPath('data.questions_and_answers.0.answers.0.text', 'First answer')
        ->assertJsonPath('data.questions_and_answers.0.answers.1.text', 'Second answer')
        ->assertJsonPath('data.questions_and_answers.1.id', $laterQuestion->id)
        ->assertJsonPath('data.questions_and_answers.1.date', 'unparsed date');
});

test('product detail returns an empty question list when none are persisted', function (): void {
    $category = createDetailCategory('No product questions');
    $brand = createDetailBrand('No Question Brand');
    $product = createDetailProduct($category, $brand, 'Product without questions');

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.questions_and_answers', []);
});

test('product detail question eager loading has constant query count', function (): void {
    $category = createDetailCategory('Question query count');
    $brand = createDetailBrand('Question Query Brand');
    $product = createDetailProduct($category, $brand, 'Question query product');

    $createQuestion = function (int $index) use ($product): void {
        $question = ProductQuestion::query()->create([
            'product_id' => $product->id,
            'source' => 'hasaki',
            'external_key' => "question-{$index}",
            'question' => "Question {$index}",
            'sort_order' => $index,
        ]);

        foreach (range(1, 3) as $answerIndex) {
            ProductQuestionAnswer::query()->create([
                'product_question_id' => $question->id,
                'source' => 'hasaki',
                'external_key' => "answer-{$index}-{$answerIndex}",
                'answer' => "Answer {$answerIndex}",
                'sort_order' => $answerIndex,
            ]);
        }
    };
    $createQuestion(1);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
    $oneQuestionQueries = count(DB::getQueryLog());

    foreach (range(2, 10) as $index) {
        $createQuestion($index);
    }

    DB::flushQueryLog();
    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonCount(10, 'data.questions_and_answers');
    $populatedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($populatedQueries)->toBe($oneQuestionQueries);
});
