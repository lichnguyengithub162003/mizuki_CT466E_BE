<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('categories endpoint returns only active categories as a hierarchy', function (): void {
    $parent = Category::query()->create([
        'name' => 'Chăm sóc da',
        'slug' => 'cham-soc-da',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    Category::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Serum',
        'slug' => 'serum',
        'sort_order' => 2,
        'is_active' => true,
    ]);
    Category::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Sữa rửa mặt',
        'slug' => 'sua-rua-mat',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    Category::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Danh mục con ẩn',
        'slug' => 'danh-muc-con-an',
        'sort_order' => 3,
        'is_active' => false,
    ]);
    Category::query()->create([
        'name' => 'Danh mục cha ẩn',
        'slug' => 'danh-muc-cha-an',
        'sort_order' => 2,
        'is_active' => false,
    ]);

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Lấy danh sách danh mục thành công!')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonPath('data.0.slug', 'cham-soc-da')
        ->assertJsonCount(2, 'data.0.children')
        ->assertJsonPath('data.0.children.0.slug', 'sua-rua-mat')
        ->assertJsonPath('data.0.children.1.slug', 'serum')
        ->assertJsonMissing(['slug' => 'danh-muc-con-an'])
        ->assertJsonMissing(['slug' => 'danh-muc-cha-an'])
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});
test('category thumbnails use one real sellable product image and propagate to parents', function (): void {
    $parent = Category::query()->create([
        'name' => 'Parent category',
        'slug' => 'thumbnail-parent',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $child = Category::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Child category',
        'slug' => 'thumbnail-child',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $empty = Category::query()->create([
        'name' => 'Empty category',
        'slug' => 'thumbnail-empty',
        'sort_order' => 2,
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Thumbnail brand',
        'slug' => 'thumbnail-brand',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $child->id,
        'brand_id' => $brand->id,
        'name' => 'Thumbnail product',
        'slug' => 'thumbnail-product',
        'is_active' => true,
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'CATEGORY-THUMBNAIL',
        'price' => 100_000,
        'weight' => 500,
        'is_active' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => '/storage/catalog/products/thumbnail/product.jpg',
        'sort_order' => 0,
        'is_primary' => true,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson('/api/v1/categories');
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();
    $categories = collect($response->json('data'));
    $parentData = $categories->firstWhere('id', $parent->id);
    $emptyData = $categories->firstWhere('id', $empty->id);
    $childData = collect($parentData['children'])->firstWhere('id', $child->id);
    $thumbnailUrl = url('/storage/catalog/products/thumbnail/product.jpg');

    expect($childData['thumbnail_url'])->toBe($thumbnailUrl)
        ->and($parentData['thumbnail_url'])->toBe($thumbnailUrl)
        ->and($emptyData['thumbnail_url'])->toBeNull()
        ->and($queries->filter(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'product_images',
        )))->toHaveCount(1);
});
