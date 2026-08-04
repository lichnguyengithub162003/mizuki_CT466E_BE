<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->category = Category::query()->create([
        'name' => 'Image remap category',
        'slug' => 'image-remap-category',
        'is_active' => true,
    ]);
    $this->brand = Brand::query()->create([
        'name' => 'Image remap brand',
        'slug' => 'image-remap-brand',
        'is_active' => true,
    ]);
});

function remapProduct(
    Category $category,
    Brand $brand,
    string $externalId,
    string $source = 'hasaki',
): Product {
    $product = Product::query()->create([
        'source' => $source,
        'external_id' => $externalId,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => "Remap product {$externalId}",
        'slug' => "remap-product-{$externalId}",
        'is_active' => true,
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => "REMAP-{$externalId}",
        'price' => 100_000,
        'weight' => 500,
        'is_active' => true,
    ]);

    return $product;
}

function numberedImageUrl(string $externalId, string $filename): string
{
    return url(Storage::disk('public')->url("catalog/products/{$externalId}/{$filename}"));
}

test('numbered files are sorted numerically primary normalized and exposed by detail API', function (): void {
    $product = remapProduct($this->category, $this->brand, '1001');

    foreach (range(1, 3) as $index) {
        ProductImage::query()->create([
            'product_id' => $product->id,
            'image_url' => "https://old.test/hash-{$index}.jpg",
            'alt_text' => $index === 1 ? 'Preserved caption' : null,
            'sort_order' => $index - 1,
            'is_primary' => $index === 1,
        ]);
    }

    foreach (['10.jpg', '2.jpg', '1.jpg'] as $filename) {
        Storage::disk('public')->put("catalog/products/1001/{$filename}", 'image-'.$filename);
    }

    $imageIds = ProductImage::query()->orderBy('id')->pluck('id')->all();

    $this->artisan('catalog:remap-numbered-images', [
        '--source' => 'hasaki',
        '--product' => '1001',
    ])->assertSuccessful();

    $images = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->get();

    expect($images->pluck('id')->all())->toBe($imageIds)
        ->and($images->pluck('image_url')->all())->toBe([
            numberedImageUrl('1001', '1.jpg'),
            numberedImageUrl('1001', '2.jpg'),
            numberedImageUrl('1001', '10.jpg'),
        ])
        ->and($images->pluck('sort_order')->all())->toBe([0, 1, 2])
        ->and($images->pluck('is_primary')->all())->toBe([true, false, false])
        ->and($images->first()->alt_text)->toBe('Preserved caption');

    $this->getJson('/api/v1/products/'.$product->slug)
        ->assertOk()
        ->assertJsonPath('data.gallery.0.image_url', numberedImageUrl('1001', '1.jpg'))
        ->assertJsonPath('data.gallery.1.image_url', numberedImageUrl('1001', '2.jpg'))
        ->assertJsonPath('data.gallery.2.image_url', numberedImageUrl('1001', '10.jpg'));

    $this->artisan('catalog:remap-numbered-images', [
        '--source' => 'hasaki',
        '--product' => '1001',
    ])->expectsTable(
        ['Metric', 'Count'],
        [
            ['scanned_products', 1],
            ['products_remapped', 1],
            ['products_skipped', 0],
            ['missing_folders', 0],
            ['numbered_files_found', 3],
            ['db_rows_inserted', 0],
            ['db_rows_updated', 0],
            ['db_rows_deleted', 0],
            ['obsolete_files_detected', 0],
            ['obsolete_files_deleted', 0],
            ['bytes_freed', 0],
            ['failures', 0],
        ],
    )->assertSuccessful();

    expect(ProductImage::query()->where('product_id', $product->id)->count())->toBe(3);
});

test('lowest numbered image is primary and row counts reconcile safely', function (): void {
    $product = remapProduct($this->category, $this->brand, '1002');
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => 'https://old.test/only.jpg',
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    Storage::disk('public')->put('catalog/products/1002/10.webp', 'ten');
    Storage::disk('public')->put('catalog/products/1002/2.png', 'two');

    $this->artisan('catalog:remap-numbered-images', [
        '--product' => '1002',
    ])->assertSuccessful();

    $images = $product->images()->orderBy('sort_order')->get();

    expect($images)->toHaveCount(2)
        ->and($images->first()->image_url)->toBe(numberedImageUrl('1002', '2.png'))
        ->and($images->first()->is_primary)->toBeTrue()
        ->and($images->last()->image_url)->toBe(numberedImageUrl('1002', '10.webp'))
        ->and($images->last()->is_primary)->toBeFalse();
});

test('dry run and default mode never delete files while missing and non source products are skipped', function (): void {
    $product = remapProduct($this->category, $this->brand, '1003');
    $missing = remapProduct($this->category, $this->brand, '1004');
    $nonHasaki = remapProduct($this->category, $this->brand, '1005', 'mizuki');
    $oldUrl = numberedImageUrl('1003', str_repeat('a', 64).'.jpg');
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => $oldUrl,
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $nonHasaki->id,
        'image_url' => 'https://existing.test/non-hasaki.jpg',
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    Storage::disk('public')->put('catalog/products/1003/1.jpg', 'numbered');
    Storage::disk('public')->put('catalog/products/1003/'.str_repeat('a', 64).'.jpg', 'hashed');
    Storage::disk('public')->put('catalog/products/1005/1.jpg', 'non-hasaki');

    $this->artisan('catalog:remap-numbered-images', [
        '--product' => '1003',
        '--dry-run' => true,
        '--delete-obsolete' => true,
    ])->assertSuccessful();

    expect($product->images()->sole()->image_url)->toBe($oldUrl)
        ->and(Storage::disk('public')->exists('catalog/products/1003/'.str_repeat('a', 64).'.jpg'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('import-reports'))->toBe([]);

    $this->artisan('catalog:remap-numbered-images', ['--product' => '1003'])
        ->assertSuccessful();

    expect(Storage::disk('public')->exists('catalog/products/1003/'.str_repeat('a', 64).'.jpg'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('import-reports'))->toBe([]);

    $this->artisan('catalog:remap-numbered-images', ['--product' => '1004'])
        ->assertSuccessful();
    expect($missing->images()->count())->toBe(0);

    $this->artisan('catalog:remap-numbered-images', ['--product' => '1005'])
        ->assertSuccessful();
    expect($nonHasaki->images()->sole()->image_url)->toBe('https://existing.test/non-hasaki.jpg');
});

test('delete obsolete removes only unreferenced hashed images inside the product folder', function (): void {
    $product = remapProduct($this->category, $this->brand, '1006');
    $variant = $product->variants()->sole();
    $obsolete = str_repeat('a', 64).'.jpg';
    $referenced = str_repeat('b', 64).'.jpg';
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => numberedImageUrl('1006', $obsolete),
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'image_url' => numberedImageUrl('1006', $referenced),
        'sort_order' => 0,
        'is_primary' => false,
    ]);

    foreach (['1.jpg', $obsolete, $referenced, 'system-logo.png'] as $filename) {
        Storage::disk('public')->put("catalog/products/1006/{$filename}", 'content-'.$filename);
    }
    Storage::disk('public')->put('catalog/products/outside-hash.jpg', 'outside');
    $obsoleteBytes = Storage::disk('public')->size("catalog/products/1006/{$obsolete}");

    $this->artisan('catalog:remap-numbered-images', [
        '--product' => '1006',
        '--delete-obsolete' => true,
    ])->assertSuccessful();

    expect(Storage::disk('public')->missing("catalog/products/1006/{$obsolete}"))->toBeTrue()
        ->and(Storage::disk('public')->exists('catalog/products/1006/1.jpg'))->toBeTrue()
        ->and(Storage::disk('public')->exists("catalog/products/1006/{$referenced}"))->toBeTrue()
        ->and(Storage::disk('public')->exists('catalog/products/1006/system-logo.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('catalog/products/outside-hash.jpg'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('import-reports'))->toHaveCount(1);

    $manifest = json_decode(
        Storage::disk('local')->get(Storage::disk('local')->allFiles('import-reports')[0]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest)->toHaveCount(1)
        ->and($manifest[0]['product_id'])->toBe($product->id)
        ->and($manifest[0]['source_external_id'])->toBe('1006')
        ->and($manifest[0]['deleted_path'])->toBe("catalog/products/1006/{$obsolete}")
        ->and($manifest[0]['bytes'])->toBe($obsoleteBytes);
});

test('unsafe product path segments are rejected before filesystem access', function (): void {
    Storage::disk('public')->put('catalog/products/outside.jpg', 'outside');

    $this->artisan('catalog:remap-numbered-images', [
        '--product' => '../outside',
        '--delete-obsolete' => true,
    ])->assertFailed();

    expect(Storage::disk('public')->exists('catalog/products/outside.jpg'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('import-reports'))->toBe([]);
});
