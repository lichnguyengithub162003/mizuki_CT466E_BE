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
 * @param list<int|array{price: int, sale_price: int|null}> $prices
 */
function createProductForListing(
    Category $category,
    Brand $brand,
    string $name,
    array $prices,
    bool $isActive = true,
): Product {
    static $sequence = 0;
    $sequence++;
    $slug = Str::slug($name).'-'.$sequence;

    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'slug' => $slug,
        'is_active' => $isActive,
        'is_featured' => false,
    ]);

    foreach ($prices as $index => $priceData) {
        $price = is_array($priceData) ? $priceData['price'] : $priceData;
        $salePrice = is_array($priceData) ? $priceData['sale_price'] : null;

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => ($index + 1) * 50 . ' ml',
            'sku' => 'TEST-'.strtoupper(Str::slug($slug, '')).'-'.($index + 1),
            'price' => $price,
            'sale_price' => $salePrice,
            'weight' => ($index + 1) * 50,
            'sort_order' => $index,
            'is_active' => true,
        ]);
    }

    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => "products/{$slug}.jpg",
        'alt_text' => $name,
        'sort_order' => 0,
        'is_primary' => true,
    ]);

    return $product;
}

function createProductListCategory(string $name, ?int $parentId = null): Category
{
    return Category::query()->create([
        'parent_id' => $parentId,
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'is_active' => true,
    ]);
}

function createProductListBrand(string $name): Brand
{
    return Brand::query()->create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'is_active' => true,
    ]);
}

test('products endpoint returns list resources with pagination metadata', function (): void {
    $category = createProductListCategory('Chăm sóc da');
    $brand = createProductListBrand('Mizuki Lab');

    createProductForListing($category, $brand, 'Sản phẩm A', [200_000]);
    createProductForListing($category, $brand, 'Sản phẩm B', [300_000]);
    createProductForListing($category, $brand, 'Sản phẩm C', [400_000]);

    $this->getJson('/api/v1/products?per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Lấy danh sách sản phẩm thành công!')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.pagination.current_page', 1)
        ->assertJsonPath('meta.pagination.per_page', 2)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.pagination.last_page', 2)
        ->assertJsonStructure([
            'success',
            'data' => [[
                'id',
                'name',
                'slug',
                'category' => ['id', 'name'],
                'brand' => ['id', 'name'],
                'primary_image_url',
                'minimum_price',
                'has_discount',
            ]],
            'message',
            'meta' => ['pagination'],
        ]);
});

test('category filter includes products from descendant categories', function (): void {
    $parent = createProductListCategory('Chăm sóc da');
    $child = createProductListCategory('Serum', $parent->id);
    $other = createProductListCategory('Trang điểm');
    $brand = createProductListBrand('Vichy');

    createProductForListing($parent, $brand, 'Sản phẩm danh mục cha', [100_000]);
    createProductForListing($child, $brand, 'Sản phẩm danh mục con', [200_000]);
    createProductForListing($other, $brand, 'Sản phẩm ngoài danh mục', [300_000]);

    $this->getJson("/api/v1/products?category_id={$parent->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['name' => 'Sản phẩm ngoài danh mục']);
});

test('product category returns the actual parent id for a child category', function (): void {
    $parent = createProductListCategory('Chăm sóc da mặt');
    $child = createProductListCategory('Tinh chất dưỡng da', $parent->id);
    $brand = createProductListBrand('Skin Lab');

    createProductForListing($child, $brand, 'Tinh chất phục hồi', [250_000]);

    $this->getJson("/api/v1/products?category_id={$child->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category.id', $child->id)
        ->assertJsonPath('data.0.category.parent_id', $parent->id);
});

test('brand filter returns only products from the selected brand', function (): void {
    $category = createProductListCategory('Sữa rửa mặt');
    $selectedBrand = createProductListBrand('Anessa');
    $otherBrand = createProductListBrand('Maybelline');

    createProductForListing($category, $selectedBrand, 'Sản phẩm Anessa', [200_000]);
    createProductForListing($category, $otherBrand, 'Sản phẩm Maybelline', [250_000]);

    $this->getJson("/api/v1/products?brand_id={$selectedBrand->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Sản phẩm Anessa');
});

test('price filter uses the lowest effective active variant price', function (): void {
    $category = createProductListCategory('Kem dưỡng');
    $brand = createProductListBrand('La Roche-Posay');

    createProductForListing($category, $brand, 'Sản phẩm đang giảm', [
        ['price' => 200_000, 'sale_price' => 150_000],
        250_000,
    ]);
    createProductForListing($category, $brand, 'Sản phẩm giá cao', [300_000]);

    $this->getJson('/api/v1/products?price_min=140000&price_max=160000')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Sản phẩm đang giảm')
        ->assertJsonPath('data.0.minimum_price', 150_000)
        ->assertJsonPath('data.0.has_discount', true);
});

test('products can be sorted by minimum price ascending and descending', function (): void {
    $category = createProductListCategory('Serum');
    $brand = createProductListBrand('L’Oréal Paris');

    createProductForListing($category, $brand, 'Giá trung bình', [200_000]);
    createProductForListing($category, $brand, 'Giá thấp', [100_000]);
    createProductForListing($category, $brand, 'Giá cao', [300_000]);

    $this->getJson('/api/v1/products?sort=price_asc')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Giá thấp')
        ->assertJsonPath('data.2.name', 'Giá cao');

    $this->getJson('/api/v1/products?sort=price_desc')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Giá cao')
        ->assertJsonPath('data.2.name', 'Giá thấp');
});

test('price minimum greater than price maximum is rejected', function (): void {
    $this->getJson('/api/v1/products?price_min=300000&price_max=100000')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Dữ liệu không hợp lệ')
        ->assertJsonPath('data.errors.price_min.0', 'Giá tối thiểu không được lớn hơn giá tối đa');
});

test('inactive products are excluded from product listing', function (): void {
    $category = createProductListCategory('Mặt nạ');
    $brand = createProductListBrand('Mizuki');

    createProductForListing($category, $brand, 'Sản phẩm đang hiển thị', [100_000]);
    createProductForListing($category, $brand, 'Sản phẩm đã ẩn', [90_000], false);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Sản phẩm đang hiển thị')
        ->assertJsonMissing(['name' => 'Sản phẩm đã ẩn']);
});

test('keyword filter searches product names', function (): void {
    $category = createProductListCategory('Chống nắng');
    $brand = createProductListBrand('Sun Lab');

    createProductForListing($category, $brand, 'Kem chống nắng dịu nhẹ', [180_000]);
    createProductForListing($category, $brand, 'Serum phục hồi', [220_000]);

    $this->getJson('/api/v1/products?keyword=chống+nắng')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Kem chống nắng dịu nhẹ');
});
