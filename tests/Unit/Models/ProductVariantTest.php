<?php

use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('it casts SKU attributes, money, and storefront fields to their expected types', function (): void {
    $variant = new ProductVariant([
        'attributes' => ['size' => '30ml'],
        'price' => '320000',
        'sale_price' => '299000',
        'weight' => '180',
        'sort_order' => '2',
        'is_active' => 1,
    ]);

    expect($variant->attributes)->toBe(['size' => '30ml'])
        ->and($variant->price)->toBeInt()->toBe(320000)
        ->and($variant->sale_price)->toBeInt()->toBe(299000)
        ->and($variant->weight)->toBeInt()->toBe(180)
        ->and($variant->sort_order)->toBeInt()->toBe(2)
        ->and($variant->is_active)->toBeTrue();
});

test('it accepts nullable variant source identity fields', function (): void {
    $variant = new ProductVariant([
        'source' => 'hasaki',
        'external_id' => '00123',
        'source_url' => 'https://example.test/products/00123',
    ]);

    expect($variant->source)->toBe('hasaki')
        ->and($variant->external_id)->toBe('00123')
        ->and($variant->source_url)->toBe('https://example.test/products/00123');
});

test('source identity migration backfills variants without changing their IDs', function (): void {
    $brand = Brand::query()->create([
        'name' => 'Migration Brand',
        'slug' => 'migration-brand',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Migration Category',
        'slug' => 'migration-category',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $productId = DB::table('products')->insertGetId([
        'source' => 'hasaki',
        'external_id' => 'migration-001',
        'source_url' => 'https://example.test/products/migration-001',
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'name' => 'Migration Product',
        'slug' => 'migration-product',
        'is_active' => true,
        'is_featured' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $variantId = DB::table('product_variants')->insertGetId([
        'product_id' => $productId,
        'name' => 'Default',
        'sku' => 'MIGRATION-SKU',
        'price' => 100_000,
        'weight' => 500,
        'sort_order' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path(
        'migrations/2026_09_08_000001_add_source_identity_to_product_variants_table.php',
    );

    $migration->down();
    expect(Schema::hasColumn('product_variants', 'source'))->toBeFalse();

    $migration->up();
    $variant = DB::table('product_variants')->where('id', $variantId)->first();

    expect($variant)->not->toBeNull()
        ->and($variant->id)->toBe($variantId)
        ->and($variant->source)->toBe('hasaki')
        ->and($variant->external_id)->toBe('migration-001')
        ->and($variant->source_url)->toBe('https://example.test/products/migration-001');
});

test('variant source identity is unique while null legacy identities remain supported', function (): void {
    $brand = Brand::query()->create([
        'name' => 'Unique Identity Brand',
        'slug' => 'unique-identity-brand',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Unique Identity Category',
        'slug' => 'unique-identity-category',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $product = Product::query()->create([
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'name' => 'Unique Identity Product',
        'slug' => 'unique-identity-product',
        'is_active' => true,
        'is_featured' => false,
    ]);
    $attributes = [
        'product_id' => $product->id,
        'source' => 'hasaki',
        'external_id' => 'unique-variant-001',
        'name' => 'Default',
        'sku' => 'UNIQUE-SKU-1',
        'price' => 100_000,
        'weight' => 500,
        'sort_order' => 0,
        'is_active' => true,
    ];

    ProductVariant::query()->create($attributes);
    ProductVariant::query()->create(array_merge($attributes, [
        'source' => null,
        'external_id' => null,
        'sku' => 'LEGACY-NULL-SKU',
    ]));

    expect(fn () => ProductVariant::query()->create(array_merge($attributes, [
        'sku' => 'UNIQUE-SKU-2',
    ])))->toThrow(QueryException::class);
});

test('it belongs to a product', function (): void {
    $variant = new ProductVariant;

    expect($variant->product()->getRelated())->toBeInstanceOf(Product::class);
});

test('it defines variant-dependent relationships', function (): void {
    $variant = new ProductVariant;

    expect($variant->images()->getRelated())->toBeInstanceOf(ProductImage::class)
        ->and($variant->inventories()->getRelated())->toBeInstanceOf(BranchInventory::class)
        ->and($variant->cartItems()->getRelated())->toBeInstanceOf(CartItem::class)
        ->and($variant->orderItems()->getRelated())->toBeInstanceOf(OrderItem::class)
        ->and($variant->reviews()->getRelated())->toBeInstanceOf(Review::class);
});
