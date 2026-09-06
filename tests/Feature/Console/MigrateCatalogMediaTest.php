<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Repositories\CatalogMediaMigrationRepository;
use App\Services\Media\CatalogMediaMigrationService;
use App\Services\Media\MediaObject;
use App\Services\Media\MediaVisibility;
use App\Services\Media\PublicMediaReference;
use App\Services\Media\PublicMediaServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('app.url', 'http://localhost:8000');
    config()->set('media.public_driver', 'cloudinary');
    config()->set('media.public_disk', 'public');
    config()->set('media.cloudinary_url', 'cloudinary://key:secret@test-cloud');
});

/** @return array{product: Product, image: ProductImage, variant: ProductVariant|null} */
function catalogMigrationImage(string $reference, bool $withVariant = false): array
{
    $token = Str::lower(Str::random(10));
    $category = Category::query()->create([
        'name' => "Migration category {$token}",
        'slug' => "migration-category-{$token}",
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => "Migration brand {$token}",
        'slug' => "migration-brand-{$token}",
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => "Migration product {$token}",
        'slug' => "migration-product-{$token}",
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = $withVariant ? ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Variant',
        'sku' => 'MIGRATION-'.Str::upper($token),
        'price' => 100000,
        'weight' => 100,
        'sort_order' => 0,
        'is_active' => true,
    ]) : null;
    $image = ProductImage::query()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'image_url' => $reference,
        'alt_text' => 'Migration alt text',
        'sort_order' => 7,
        'is_primary' => true,
    ]);

    return compact('product', 'image', 'variant');
}

function successfulCatalogMigrationMedia(int $expectedUploads = 1): MockInterface
{
    $references = app(PublicMediaReference::class);
    $media = Mockery::mock(PublicMediaServiceContract::class);
    $media->shouldReceive('put')->times($expectedUploads)->andReturnUsing(
        fn (string $key, mixed $contents, ?string $mimeType, ?string $originalName): MediaObject => new MediaObject(
            key: $references->cloudinaryFromLocal($key),
            visibility: MediaVisibility::Public,
            mimeType: $mimeType,
            extension: pathinfo($key, PATHINFO_EXTENSION),
            bytes: filesize($contents) ?: null,
            originalName: $originalName,
        ),
    );

    return $media;
}

test('catalog media dry-run plans local images without upload or database mutation', function (): void {
    Storage::disk('public')->put('catalog/products/101/1.jpg', 'dry-run-image');
    $context = catalogMigrationImage('http://localhost:8000/storage/catalog/products/101/1.jpg');
    $media = Mockery::mock(PublicMediaServiceContract::class);
    $media->shouldNotReceive('put');
    $media->shouldNotReceive('delete');
    app()->instance(PublicMediaServiceContract::class, $media);

    $result = app(CatalogMediaMigrationService::class)->execute(dryRun: true);

    expect($result['products_inspected'])->toBe(1)
        ->and($result['image_records_inspected'])->toBe(1)
        ->and($result['eligible'])->toBe(1)
        ->and($result['planned_uploads'])->toBe(1)
        ->and($result['planned_db_updates'])->toBe(1)
        ->and($result['successful_uploads'])->toBe(0)
        ->and($context['image']->fresh()->image_url)->toBe($context['image']->image_url);
});

test('catalog media migration skips already migrated and missing sources', function (): void {
    catalogMigrationImage('cloudinary:mizuki/products/1/gallery/550e8400-e29b-41d4-a716-446655440000');
    catalogMigrationImage('http://localhost:8000/storage/catalog/products/missing/1.jpg');
    $media = Mockery::mock(PublicMediaServiceContract::class);
    $media->shouldNotReceive('put');
    $media->shouldNotReceive('delete');
    app()->instance(PublicMediaServiceContract::class, $media);

    $result = app(CatalogMediaMigrationService::class)->execute();

    expect($result['already_cloudinary'])->toBe(1)
        ->and($result['missing_source'])->toBe(1)
        ->and($result['skipped'])->toBe(2)
        ->and($result['db_updates'])->toBe(0);
});

test('successful migration preserves product and variant image metadata', function (): void {
    Storage::disk('public')->put('catalog/products/202/variant.png', 'variant-image');
    $context = catalogMigrationImage('/storage/catalog/products/202/variant.png', true);
    $media = successfulCatalogMigrationMedia();
    $media->shouldNotReceive('delete');
    app()->instance(PublicMediaServiceContract::class, $media);

    $result = app(CatalogMediaMigrationService::class)->execute();
    $migrated = $context['image']->fresh();

    expect($result['successful_uploads'])->toBe(1)
        ->and($result['db_updates'])->toBe(1)
        ->and($migrated->image_url)->toMatch("~^cloudinary:mizuki/products/{$context['product']->id}/variants/{$context['variant']->id}/[0-9a-f-]{36}$~")
        ->and($migrated->product_id)->toBe($context['product']->id)
        ->and($migrated->product_variant_id)->toBe($context['variant']->id)
        ->and($migrated->alt_text)->toBe('Migration alt text')
        ->and($migrated->sort_order)->toBe(7)
        ->and($migrated->is_primary)->toBeTrue();
});

test('upload failure leaves the database reference unchanged and continues safely', function (): void {
    Storage::disk('public')->put('catalog/products/303/1.jpg', 'failed-upload');
    Storage::disk('public')->put('catalog/products/304/1.jpg', 'successful-upload');
    $failed = catalogMigrationImage('catalog/products/303/1.jpg');
    $succeeded = catalogMigrationImage('catalog/products/304/1.jpg');
    $attempt = 0;
    $references = app(PublicMediaReference::class);
    $media = Mockery::mock(PublicMediaServiceContract::class);
    $media->shouldReceive('put')->twice()->andReturnUsing(
        function (string $key, mixed $contents, ?string $mimeType, ?string $originalName) use (&$attempt, $references): MediaObject {
            if (++$attempt === 1) {
                throw new RuntimeException('Provider unavailable');
            }

            return new MediaObject(
                key: $references->cloudinaryFromLocal($key),
                visibility: MediaVisibility::Public,
                mimeType: $mimeType,
                extension: pathinfo($key, PATHINFO_EXTENSION),
                bytes: filesize($contents) ?: null,
                originalName: $originalName,
            );
        },
    );
    $media->shouldNotReceive('delete');
    app()->instance(PublicMediaServiceContract::class, $media);

    $result = app(CatalogMediaMigrationService::class)->execute();

    expect($result['failed_uploads'])->toBe(1)
        ->and($result['successful_uploads'])->toBe(1)
        ->and($result['db_updates'])->toBe(1)
        ->and($failed['image']->fresh()->image_url)->toBe('catalog/products/303/1.jpg')
        ->and($succeeded['image']->fresh()->image_url)->toStartWith('cloudinary:mizuki/products/');
});

test('migration never uploads external private or staging references', function (): void {
    catalogMigrationImage('https://cdn.example.test/product.jpg');
    catalogMigrationImage('private/refunds/1/evidence.jpg');
    catalogMigrationImage('staging/1/token/image.jpg');
    $media = Mockery::mock(PublicMediaServiceContract::class);
    $media->shouldNotReceive('put');
    $media->shouldNotReceive('delete');
    app()->instance(PublicMediaServiceContract::class, $media);

    $result = app(CatalogMediaMigrationService::class)->execute();

    expect($result['external'])->toBe(1)
        ->and($result['non_public'])->toBe(2)
        ->and($result['skipped'])->toBe(3)
        ->and($result['db_updates'])->toBe(0);
});

test('database update failure destroys the newly uploaded asset', function (): void {
    Storage::disk('public')->put('catalog/products/404/1.jpg', 'failed-database');
    $context = catalogMigrationImage('catalog/products/404/1.jpg');
    $media = successfulCatalogMigrationMedia();
    $media->shouldReceive('delete')
        ->once()
        ->with(Mockery::on(fn (string $reference): bool => str_starts_with($reference, 'cloudinary:mizuki/products/')))
        ->andReturnTrue();
    app()->instance(PublicMediaServiceContract::class, $media);

    $repository = Mockery::mock(CatalogMediaMigrationRepository::class)->makePartial();
    $repository->shouldReceive('replaceReference')->once()->andThrow(new RuntimeException('Database unavailable'));
    app()->instance(CatalogMediaMigrationRepository::class, $repository);

    $result = app(CatalogMediaMigrationService::class)->execute();

    expect($result['db_failures'])->toBe(1)
        ->and($result['compensated_failures'])->toBe(1)
        ->and($result['db_updates'])->toBe(0)
        ->and($context['image']->fresh()->image_url)->toBe('catalog/products/404/1.jpg');
});

test('rerunning migration skips the canonical reference without a duplicate upload', function (): void {
    Storage::disk('public')->put('catalog/products/505/1.webp', 'idempotent-image');
    $context = catalogMigrationImage('storage/catalog/products/505/1.webp');
    $media = successfulCatalogMigrationMedia();
    $media->shouldNotReceive('delete');
    app()->instance(PublicMediaServiceContract::class, $media);

    $first = app(CatalogMediaMigrationService::class)->execute();
    $second = app(CatalogMediaMigrationService::class)->execute();

    expect($first['db_updates'])->toBe(1)
        ->and($second['already_cloudinary'])->toBe(1)
        ->and($second['successful_uploads'])->toBe(0)
        ->and($context['image']->fresh()->image_url)->toStartWith('cloudinary:mizuki/products/');
});

test('catalog migration command validates bounded selection options', function (): void {
    $this->artisan('media:migrate-catalog', ['--limit' => 101, '--dry-run' => true])
        ->expectsOutput('Batch limit must be between 1 and 100 products.')
        ->assertFailed();
});
