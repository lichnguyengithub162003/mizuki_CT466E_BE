<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{0: Category, 1: Brand}
 */
function createSearchCatalogContext(): array
{
    $token = Str::lower(Str::random(8));
    $category = Category::query()->create([
        'name' => 'Danh mục tìm kiếm '.$token,
        'slug' => 'search-category-'.$token,
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Thương hiệu tìm kiếm '.$token,
        'slug' => 'search-brand-'.$token,
        'is_active' => true,
    ]);

    return [$category, $brand];
}

function createSearchProduct(
    Category $category,
    Brand $brand,
    string $name,
    bool $isActive = true,
    int $price = 200_000,
): Product {
    static $sequence = 0;
    $sequence++;
    $slug = Str::slug($name).'-search-'.$sequence;

    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'slug' => $slug,
        'is_active' => $isActive,
        'is_featured' => false,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '50 ml',
        'sku' => 'SEARCH-'.Str::upper(Str::random(10)),
        'price' => $price,
        'weight' => 50,
        'sort_order' => 0,
        'is_active' => true,
    ]);

    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => "products/{$slug}.jpg",
        'alt_text' => $name,
        'sort_order' => 0,
        'is_primary' => true,
    ]);

    return $product;
}

test('product search returns products matching the keyword', function (): void {
    [$category, $brand] = createSearchCatalogContext();
    createSearchProduct($category, $brand, 'Serum phục hồi da', price: 180_000);
    createSearchProduct($category, $brand, 'Tinh chất Serum dưỡng ẩm', price: 220_000);
    createSearchProduct($category, $brand, 'Kem chống nắng', price: 250_000);

    $this->getJson('/api/v1/products/search?keyword=serum')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Tìm kiếm thành công!')
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'success',
            'data' => [[
                'id', 'name', 'slug', 'primary_image_url', 'minimum_price',
            ]],
            'message',
            'meta',
        ])
        ->assertJsonMissingPath('meta.pagination')
        ->assertJsonMissing(['name' => 'Kem chống nắng']);
});

test('product search respects the requested result limit', function (): void {
    [$category, $brand] = createSearchCatalogContext();

    for ($index = 1; $index <= 5; $index++) {
        createSearchProduct($category, $brand, "Serum số {$index}");
    }

    $this->getJson('/api/v1/products/search?keyword=serum&limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('product search prioritizes names starting with the keyword', function (): void {
    [$category, $brand] = createSearchCatalogContext();
    createSearchProduct($category, $brand, 'Kem dưỡng có Serum');
    createSearchProduct($category, $brand, 'Serum phục hồi chuyên sâu');

    $this->getJson('/api/v1/products/search?keyword=serum')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Serum phục hồi chuyên sâu')
        ->assertJsonPath('data.1.name', 'Kem dưỡng có Serum');
});

test('product search rejects an empty keyword', function (): void {
    $this->getJson('/api/v1/products/search?keyword=')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Dữ liệu không hợp lệ')
        ->assertJsonPath('data.errors.keyword.0', 'Vui lòng nhập từ khóa tìm kiếm');
});

test('product search excludes inactive products', function (): void {
    [$category, $brand] = createSearchCatalogContext();
    createSearchProduct($category, $brand, 'Serum đang bán');
    createSearchProduct($category, $brand, 'Serum đã ẩn', false);

    $this->getJson('/api/v1/products/search?keyword=serum')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Serum đang bán')
        ->assertJsonMissing(['name' => 'Serum đã ẩn']);
});
