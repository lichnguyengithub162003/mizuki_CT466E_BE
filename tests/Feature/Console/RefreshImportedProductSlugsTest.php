<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function slugRefreshProduct(
    Category $category,
    Brand $brand,
    string $source,
    ?string $externalId,
    string $name,
    string $slug,
): Product {
    return Product::query()->create([
        'source' => $source,
        'external_id' => $externalId,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'slug' => $slug,
        'is_active' => true,
    ]);
}

beforeEach(function (): void {
    $this->category = Category::query()->create([
        'name' => 'Imported category',
        'slug' => 'imported-category',
        'is_active' => true,
    ]);
    $this->brand = Brand::query()->create([
        'name' => 'Imported brand',
        'slug' => 'imported-brand',
        'is_active' => true,
    ]);
});

test('slug refresh supports dry run writes only slugs and reruns idempotently', function (): void {
    $imported = slugRefreshProduct(
        $this->category,
        $this->brand,
        'hasaki',
        '107091',
        'Thực Phẩm Bảo Vệ Sức Khỏe Costar Royal Jelly',
        'hasaki-product-107091',
    );
    $nonHasaki = slugRefreshProduct(
        $this->category,
        $this->brand,
        'mizuki',
        null,
        'Mizuki original',
        'mizuki-original',
    );
    ProductVariant::query()->create([
        'product_id' => $imported->id,
        'name' => 'Default',
        'sku' => 'SLUG-REFRESH-107091',
        'price' => 100_000,
        'weight' => 500,
        'is_active' => true,
    ]);
    $originalProduct = $imported->only([
        'name',
        'category_id',
        'brand_id',
        'source',
        'external_id',
        'is_active',
    ]);

    $this->artisan('mizuki:refresh-imported-product-slugs', [
        '--source' => 'hasaki',
        '--dry-run' => true,
    ])->expectsOutputToContain('updated')
        ->assertSuccessful();

    expect($imported->refresh()->slug)->toBe('hasaki-product-107091');

    $this->artisan('mizuki:refresh-imported-product-slugs', ['--source' => 'hasaki'])
        ->assertSuccessful();

    $newSlug = 'thuc-pham-bao-ve-suc-khoe-costar-royal-jelly-107091';

    expect($imported->refresh()->slug)->toBe($newSlug)
        ->and($imported->only(array_keys($originalProduct)))->toBe($originalProduct)
        ->and($nonHasaki->refresh()->slug)->toBe('mizuki-original')
        ->and(Product::query()->distinct()->count('slug'))->toBe(2);

    $this->getJson("/api/v1/products/{$newSlug}")
        ->assertOk()
        ->assertJsonPath('data.slug', $newSlug);

    $this->artisan('mizuki:refresh-imported-product-slugs', ['--source' => 'hasaki'])
        ->expectsTable(
            ['Metric', 'Count'],
            [['total', 1], ['updated', 0], ['unchanged', 1], ['conflicts', 0]],
        )
        ->assertSuccessful();
});

test('slug refresh reports a uniqueness conflict without changing either product', function (): void {
    $imported = slugRefreshProduct(
        $this->category,
        $this->brand,
        'hasaki',
        '1001',
        'Conflicting product',
        'hasaki-product-1001',
    );
    $owner = slugRefreshProduct(
        $this->category,
        $this->brand,
        'mizuki',
        null,
        'Existing product',
        'conflicting-product-1001',
    );

    $this->artisan('mizuki:refresh-imported-product-slugs', ['--source' => 'hasaki'])
        ->expectsTable(
            ['Metric', 'Count'],
            [['total', 1], ['updated', 0], ['unchanged', 0], ['conflicts', 1]],
        )
        ->assertFailed();

    expect($imported->refresh()->slug)->toBe('hasaki-product-1001')
        ->and($owner->refresh()->slug)->toBe('conflicting-product-1001');
});
