<?php

use App\Enums\BranchType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use App\Services\Import\ProductImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bridgeCategory(string $name, string $slug, ?Category $parent = null): Category
{
    return Category::query()->create([
        'parent_id' => $parent?->id,
        'name' => $name,
        'slug' => $slug,
        'is_active' => true,
    ]);
}

function bridgeBrand(string $name, string $slug): Brand
{
    return Brand::query()->create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
}

/** @return array{0: Product, 1: ProductVariant} */
function bridgeProduct(
    Category $category,
    Brand $brand,
    string $name,
    string $slug,
    string $sku,
    int $price,
    ?int $salePrice = null,
    bool $active = true,
): array {
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'slug' => $slug,
        'description' => '<p>Mô tả an toàn</p>',
        'ingredients' => '<p>Niacinamide</p>',
        'usage_instructions' => '<p>Dùng buổi tối</p>',
        'specifications' => ['Loại da' => 'Da nhạy cảm'],
        'is_active' => $active,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '50ml',
        'sku' => $sku,
        'attributes' => ['capacity' => '50ml'],
        'price' => $price,
        'sale_price' => $salePrice,
        'weight' => 500,
        'is_active' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => "/storage/catalog/{$slug}.jpg",
        'alt_text' => $name,
        'sort_order' => 0,
        'is_primary' => true,
    ]);

    return [$product, $variant];
}

function bridgeRetailBranch(): Branch
{
    return Branch::query()->create([
        'code' => 'CATALOG-BRIDGE',
        'name' => 'Mizuki Catalog Branch',
        'branch_type' => BranchType::Store,
        'phone' => '02923888888',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
}

test('listing is frontend ready and supports category brand stock search sort and pagination', function (): void {
    $root = bridgeCategory('Chăm sóc da', 'bridge-skin');
    $serum = bridgeCategory('Serum', 'bridge-serum', $root);
    $brandA = bridgeBrand('Mizuki Lab', 'bridge-mizuki');
    $brandB = bridgeBrand('Other Lab', 'bridge-other');
    [$first, $firstVariant] = bridgeProduct(
        $serum,
        $brandA,
        'Serum phục hồi',
        'bridge-serum-phuc-hoi',
        'BRIDGE-SKU-001',
        220_000,
        180_000,
    );
    [$second] = bridgeProduct(
        $serum,
        $brandB,
        'Kem dưỡng',
        'bridge-kem-duong',
        'BRIDGE-SKU-002',
        150_000,
    );
    bridgeProduct(
        $serum,
        $brandA,
        'Sản phẩm ẩn',
        'bridge-hidden',
        'BRIDGE-SKU-HIDDEN',
        100_000,
        active: false,
    );
    $branch = bridgeRetailBranch();
    BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $firstVariant->id,
        'quantity' => 10,
        'reserved_quantity' => 2,
    ]);
    Review::query()->create([
        'user_id' => User::factory()->create()->id,
        'product_id' => $first->id,
        'rating' => 5,
        'is_visible' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $first->id,
        'image_url' => ProductImageImportService::FALLBACK_URL,
        'alt_text' => $first->name,
        'sort_order' => 0,
        'is_primary' => true,
    ]);

    $this->getJson("/api/v1/products?category_id={$root->id}&brand_id={$brandA->id}&branch_id={$branch->id}&in_stock=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.primary_image', '/storage/catalog/bridge-serum-phuc-hoi.jpg')
        ->assertJsonPath('data.0.price', 180_000)
        ->assertJsonPath('data.0.original_price', 220_000)
        ->assertJsonPath('data.0.discount.amount', 40_000)
        ->assertJsonPath('data.0.rating', 5)
        ->assertJsonPath('data.0.review_count', 1)
        ->assertJsonPath('data.0.default_variant.id', $firstVariant->id)
        ->assertJsonPath('data.0.availability.available_quantity', 8)
        ->assertJsonStructure(['meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);

    $this->getJson('/api/v1/products?keyword=BRIDGE-SKU-002')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/v1/products?sort=price_asc&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.per_page', 1);
    $this->getJson('/api/v1/products?sort=rating')
        ->assertOk()->assertJsonPath('data.0.id', $first->id);
});

test('detail works by slug or id and exposes stable frontend sections', function (): void {
    $root = bridgeCategory('Chăm sóc da', 'detail-root');
    $leaf = bridgeCategory('Tinh chất', 'detail-leaf', $root);
    $brand = bridgeBrand('Mizuki', 'detail-mizuki');
    [$product, $variant] = bridgeProduct(
        $leaf,
        $brand,
        'Tinh chất Mizuki',
        'detail-tinh-chat',
        'DETAIL-BRIDGE-SKU',
        300_000,
        250_000,
    );
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => ProductImageImportService::FALLBACK_URL,
        'alt_text' => $product->name,
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => '/storage/catalog/detail-tinh-chat-2.jpg',
        'alt_text' => $product->name,
        'sort_order' => 1,
        'is_primary' => false,
    ]);
    $branch = bridgeRetailBranch();
    BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 7,
        'reserved_quantity' => 1,
    ]);

    foreach ([$product->slug, (string) $product->id] as $identifier) {
        $this->getJson("/api/v1/products/{$identifier}")
            ->assertOk()
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.brand.slug', $brand->slug)
            ->assertJsonCount(2, 'data.categories')
            ->assertJsonPath('data.breadcrumbs.0.id', $root->id)
            ->assertJsonPath('data.breadcrumbs.1.id', $leaf->id)
            ->assertJsonCount(2, 'data.gallery')
            ->assertJsonPath('data.gallery.0.image_url', '/storage/catalog/detail-tinh-chat.jpg')
            ->assertJsonPath('data.gallery.1.image_url', '/storage/catalog/detail-tinh-chat-2.jpg')
            ->assertJsonPath('data.variants.0.sku', 'DETAIL-BRIDGE-SKU')
            ->assertJsonPath('data.prices.minimum', 250_000)
            ->assertJsonPath('data.specifications.Loại da', 'Da nhạy cảm')
            ->assertJsonPath('data.branch_availability.0.available_quantity', 6)
            ->assertJsonPath('data.related_products', [])
            ->assertJsonPath('data.reviews', [])
            ->assertJsonPath('data.questions_and_answers', []);
    }
});

test('catalog resources use placeholder only when a product has no real images', function (): void {
    $category = bridgeCategory('Fallback', 'bridge-fallback');
    $brand = bridgeBrand('Fallback Brand', 'bridge-fallback-brand');
    [$product] = bridgeProduct(
        $category,
        $brand,
        'Sản phẩm chưa có ảnh',
        'bridge-no-image',
        'BRIDGE-NO-IMAGE',
        100_000,
    );
    $product->images()->delete();

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.primary_image', ProductImageImportService::FALLBACK_URL)
        ->assertJsonPath('data.0.primary_image_url', ProductImageImportService::FALLBACK_URL);

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonCount(1, 'data.gallery')
        ->assertJsonPath('data.gallery.0.image_url', ProductImageImportService::FALLBACK_URL)
        ->assertJsonPath('data.gallery.0.is_primary', true);
});
test('catalog uses external aggregate ratings until internal reviews exist', function (): void {
    $category = bridgeCategory('Rating', 'bridge-rating');
    $brand = bridgeBrand('Rating Brand', 'bridge-rating-brand');
    [$externalProduct] = bridgeProduct(
        $category,
        $brand,
        'External rating product',
        'bridge-external-rating',
        'BRIDGE-EXTERNAL-RATING',
        100_000,
    );
    $externalProduct->update([
        'external_rating' => 4.36,
        'external_review_count' => 143,
    ]);
    [$internalProduct] = bridgeProduct(
        $category,
        $brand,
        'Internal rating product',
        'bridge-internal-rating',
        'BRIDGE-INTERNAL-RATING',
        120_000,
    );
    $internalProduct->update([
        'external_rating' => 4.90,
        'external_review_count' => 999,
    ]);
    Review::query()->create([
        'user_id' => User::factory()->create()->id,
        'product_id' => $internalProduct->id,
        'rating' => 2,
        'is_visible' => true,
    ]);

    $this->getJson('/api/v1/products?keyword=External%20rating%20product')
        ->assertOk()
        ->assertJsonPath('data.0.rating', 4.4)
        ->assertJsonPath('data.0.review_count', 143);

    $this->getJson("/api/v1/products/{$externalProduct->slug}")
        ->assertOk()
        ->assertJsonPath('data.rating', 4.4)
        ->assertJsonPath('data.review_count', 143);

    $this->getJson('/api/v1/products?keyword=Internal%20rating%20product')
        ->assertOk()
        ->assertJsonPath('data.0.rating', 2)
        ->assertJsonPath('data.0.review_count', 1);

    $this->getJson("/api/v1/products/{$internalProduct->slug}")
        ->assertOk()
        ->assertJsonPath('data.rating', 2)
        ->assertJsonPath('data.review_count', 1);
});
test('search suggestions match product sku and brand while inactive products remain hidden', function (): void {
    $category = bridgeCategory('Search', 'bridge-search');
    $brand = bridgeBrand('Unique Bridge Brand', 'unique-bridge-brand');
    [$product] = bridgeProduct(
        $category,
        $brand,
        'Dưỡng ẩm chuyên sâu',
        'bridge-search-product',
        'UNIQUE-BRIDGE-SKU',
        200_000,
    );
    bridgeProduct(
        $category,
        $brand,
        'Dưỡng ẩm đã ẩn',
        'bridge-search-hidden',
        'HIDDEN-BRIDGE-SKU',
        100_000,
        active: false,
    );

    $this->getJson('/api/v1/products/search?keyword=UNIQUE-BRIDGE-SKU')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
    $this->getJson('/api/v1/products/search?keyword=Unique%20Bridge%20Brand')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
});
