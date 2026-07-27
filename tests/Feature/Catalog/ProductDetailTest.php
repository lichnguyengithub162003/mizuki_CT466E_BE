<?php

use App\Models\Brand;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
