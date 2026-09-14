<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFavorite;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Import\ProductJsonImportService;
use App\Services\Import\ProductVariantNormalizationApplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{manifest: string, identifier: string, canonical: Product, products: list<Product>, variants: list<ProductVariant>, image_urls: array<int, list<string>>, brand: Brand, category: Category}
 */
function normalizationGroup(int $members = 2, string $identifier = 'pvg-2662bdfed5f64671'): array
{
    $token = Str::lower(Str::random(8));
    $approvedExternalIds = match ($identifier) {
        'pvg-2662bdfed5f64671' => ['275921', '302225'],
        'pvg-75d54149cdf053fa' => ['246464', '246468'],
        'pvg-fee422273f0bb788' => ['221371', '221379', '221375'],
        default => throw new InvalidArgumentException('Unsupported normalization test group.'),
    };
    if (count($approvedExternalIds) !== $members) {
        throw new InvalidArgumentException('Normalization test member count does not match its approved group.');
    }
    $brand = Brand::query()->create([
        'name' => "Normalization Brand {$token}",
        'slug' => "normalization-brand-{$token}",
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => "Normalization Category {$token}",
        'slug' => "normalization-category-{$token}",
        'is_active' => true,
    ]);
    $products = [];
    $variants = [];
    $identities = [];
    $imageUrls = [];

    for ($index = 1; $index <= $members; $index++) {
        $externalId = $approvedExternalIds[$index - 1];
        $product = Product::query()->create([
            'source' => 'hasaki',
            'external_id' => $externalId,
            'source_url' => "https://example.test/products/{$externalId}",
            'source_variant_groups' => [],
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => "Normalization Product {$token} {$index}",
            'slug' => "normalization-product-{$token}-{$index}",
            'is_active' => true,
            'is_featured' => false,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'source' => 'hasaki',
            'external_id' => $externalId,
            'source_url' => "https://example.test/products/{$externalId}",
            'name' => "Variant {$index}",
            'sku' => 'NORMALIZE-'.Str::upper($token)."-{$index}",
            'attributes' => ['color' => "color-{$index}"],
            'price' => 100000 + $index,
            'weight' => 100,
            'sort_order' => $index - 1,
            'is_active' => true,
        ]);
        $imageUrls[$product->id] = [];
        foreach ([0, 1] as $sortOrder) {
            $url = "https://example.test/images/{$externalId}-{$sortOrder}.jpg";
            ProductImage::query()->create([
                'product_id' => $product->id,
                'product_variant_id' => null,
                'image_url' => $url,
                'alt_text' => "Source {$index} image {$sortOrder}",
                'sort_order' => $sortOrder,
                'is_primary' => $sortOrder === 0,
            ]);
            $imageUrls[$product->id][] = $url;
        }
        $products[] = $product;
        $variants[] = $variant;
        $identities[] = ['source' => 'hasaki', 'external_id' => $externalId];
    }

    $group = [
        'classification' => 'auto_safe',
        'candidate_product_ids' => array_map(fn (Product $product): int => $product->id, $products),
        'candidate_variant_ids' => array_map(fn (ProductVariant $variant): int => $variant->id, $variants),
        'source_external_ids' => $identities,
        'brand' => ['id' => $brand->id, 'name' => $brand->name],
        'category' => ['id' => $category->id, 'name' => $category->name],
        'failed_hard_gates' => [],
        'operational_conflicts' => [
            'by_product' => [],
            'totals' => ['inventory' => 0, 'cart_items' => 0, 'order_items' => 0, 'reviews' => 0, 'questions' => 0, 'favorites' => 0],
        ],
        'recommended_canonical_product_id' => $products[0]->id,
        'group_identifier' => $identifier,
    ];
    $manifest = tempnam(sys_get_temp_dir(), "mizuki-normalization-{$token}-");
    if ($manifest === false) {
        throw new RuntimeException('Unable to create normalization test manifest.');
    }
    file_put_contents($manifest, json_encode(['summary' => [], 'groups' => [$group]], JSON_THROW_ON_ERROR));
    config()->set("product_variant_normalization.approved_groups.{$identifier}", [
        'product_ids' => $group['candidate_product_ids'],
        'variant_ids' => $group['candidate_variant_ids'],
        'canonical_product_id' => $group['recommended_canonical_product_id'],
        'source_external_ids' => $group['source_external_ids'],
        'brand_id' => $brand->id,
        'category_id' => $category->id,
    ]);

    return [
        'manifest' => $manifest,
        'identifier' => $identifier,
        'canonical' => $products[0],
        'products' => $products,
        'variants' => $variants,
        'image_urls' => $imageUrls,
        'brand' => $brand,
        'category' => $category,
    ];
}

function applyNormalization(array $fixture): array
{
    return app(ProductVariantNormalizationApplyService::class)->execute(
        $fixture['manifest'],
        [$fixture['identifier']],
        true,
    );
}

test('default command mode validates a selected group without mutation', function (): void {
    $fixture = normalizationGroup();

    $this->artisan('products:normalize-variants', [
        '--manifest' => $fixture['manifest'],
        '--group' => [$fixture['identifier']],
    ])->expectsOutput('Product–Variant normalization dry-run')->assertSuccessful();

    expect($fixture['products'][1]->fresh()->trashed())->toBeFalse()
        ->and($fixture['variants'][1]->fresh()->product_id)->toBe($fixture['products'][1]->id)
        ->and(ProductImage::query()->whereNotNull('product_variant_id')->count())->toBe(0);
});

test('successful two-product merge preserves operational references variant identity and image attribution', function (): void {
    $fixture = normalizationGroup();
    [$canonical, $duplicate] = $fixture['products'];
    [$canonicalVariant, $duplicateVariant] = $fixture['variants'];
    $branch = Branch::query()->create([
        'code' => 'NORM-'.Str::upper(Str::random(6)),
        'name' => 'Normalization Branch',
        'phone' => '02923999999',
        'email' => Str::lower(Str::random(6)).'@normalization.test',
        'address' => 'Can Tho',
        'province_code' => '710',
        'ghn_district_id' => 1572,
        'ghn_ward_code' => '550307',
        'is_active' => true,
    ]);
    $customer = User::factory()->create();
    $cart = Cart::query()->create(['user_id' => $customer->id, 'branch_id' => $branch->id]);
    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $duplicateVariant->id,
        'quantity' => 5,
    ]);
    $cartItem = CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_variant_id' => $duplicateVariant->id,
        'quantity' => 1,
    ]);
    $order = Order::query()->create([
        'order_number' => 'NORM-'.Str::upper(Str::random(12)),
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Pending,
        'subtotal' => 100001,
        'total_amount' => 100001,
    ]);
    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_variant_id' => $duplicateVariant->id,
        'product_id' => $duplicate->id,
        'product_name' => $duplicate->name,
        'variant_name' => $duplicateVariant->name,
        'sku' => $duplicateVariant->sku,
        'unit_price' => 100001,
        'quantity' => 1,
        'line_total' => 100001,
    ]);
    $variantIdentities = collect($fixture['variants'])->mapWithKeys(
        fn (ProductVariant $variant): array => [$variant->id => [$variant->source, $variant->external_id]],
    )->all();

    $result = applyNormalization($fixture);

    expect($result['groups_normalized'])->toBe(1)
        ->and($result['variants_reparented'])->toBe(1)
        ->and($result['products_retired'])->toBe(1)
        ->and($canonicalVariant->fresh()->id)->toBe($canonicalVariant->id)
        ->and($duplicateVariant->fresh()->id)->toBe($duplicateVariant->id)
        ->and($duplicateVariant->fresh()->product_id)->toBe($canonical->id)
        ->and([$canonicalVariant->fresh()->source, $canonicalVariant->fresh()->external_id])->toBe($variantIdentities[$canonicalVariant->id])
        ->and([$duplicateVariant->fresh()->source, $duplicateVariant->fresh()->external_id])->toBe($variantIdentities[$duplicateVariant->id])
        ->and($inventory->fresh()->product_variant_id)->toBe($duplicateVariant->id)
        ->and($cartItem->fresh()->product_variant_id)->toBe($duplicateVariant->id)
        ->and($orderItem->fresh()->product_variant_id)->toBe($duplicateVariant->id)
        ->and($orderItem->fresh()->product_id)->toBe($canonical->id)
        ->and($duplicate->fresh()->trashed())->toBeTrue()
        ->and($duplicate->fresh()->is_active)->toBeFalse();

    foreach ($fixture['products'] as $index => $sourceProduct) {
        $variant = $fixture['variants'][$index];
        $images = ProductImage::query()->where('product_variant_id', $variant->id)->orderBy('sort_order')->get();
        expect($images->pluck('product_id')->unique()->all())->toBe([$canonical->id])
            ->and($images->pluck('image_url')->all())->toBe($fixture['image_urls'][$sourceProduct->id])
            ->and($images->where('is_primary', true))->toHaveCount(1);
    }
});

test('successful three-product merge reparents every existing variant without collapsing galleries', function (): void {
    $fixture = normalizationGroup(3, 'pvg-fee422273f0bb788');
    $variantIds = array_map(fn (ProductVariant $variant): int => $variant->id, $fixture['variants']);

    $result = applyNormalization($fixture);

    expect($result['variants_reparented'])->toBe(2)
        ->and($result['products_retired'])->toBe(2)
        ->and(ProductVariant::query()->whereIn('id', $variantIds)->pluck('product_id')->unique()->all())
        ->toBe([$fixture['canonical']->id]);
    foreach ($fixture['variants'] as $variant) {
        expect(ProductImage::query()->where('product_variant_id', $variant->id)->count())->toBe(2)
            ->and(ProductImage::query()->where('product_variant_id', $variant->id)->where('is_primary', true)->count())->toBe(1);
    }
});

test('product-owned operational conflict blocks the merge', function (): void {
    $fixture = normalizationGroup();
    ProductFavorite::query()->create([
        'user_id' => User::factory()->create()->id,
        'product_id' => $fixture['products'][1]->id,
    ]);

    expect(fn () => applyNormalization($fixture))
        ->toThrow(RuntimeException::class, 'Product-owned product_favorites references block');
    expect($fixture['variants'][1]->fresh()->product_id)->toBe($fixture['products'][1]->id)
        ->and($fixture['products'][1]->fresh()->trashed())->toBeFalse();
});

test('stale manifest identity blocks the merge', function (): void {
    $fixture = normalizationGroup();
    $fixture['products'][1]->update(['external_id' => 'stale-external-id']);

    expect(fn () => applyNormalization($fixture))
        ->toThrow(RuntimeException::class, 'differs from the manifest');
});

test('apply rejects a group outside the explicit allowlist', function (): void {
    expect(fn () => app(ProductVariantNormalizationApplyService::class)->execute(
        'not-read.json',
        ['pvg-not-approved'],
        true,
    ))->toThrow(InvalidArgumentException::class, 'Unapproved normalization group')
        ->and(fn () => app(ProductVariantNormalizationApplyService::class)->execute(
            'not-read.json',
            [],
            true,
        ))->toThrow(InvalidArgumentException::class, '--apply requires at least one explicit --group');
});

test('conflicting primary images block normalization', function (): void {
    $fixture = normalizationGroup();
    ProductImage::query()->where('product_id', $fixture['products'][1]->id)
        ->update(['is_primary' => true]);

    expect(fn () => applyNormalization($fixture))
        ->toThrow(RuntimeException::class, 'primary-image conflict blocks');
    expect($fixture['variants'][1]->fresh()->product_id)->toBe($fixture['products'][1]->id);
});

test('failure during mutation rolls back the whole group', function (): void {
    $fixture = normalizationGroup();
    $throw = true;
    DB::listen(function ($query) use (&$throw): void {
        if ($throw && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'product_variants')) {
            $throw = false;
            throw new RuntimeException('Injected normalization failure');
        }
    });

    expect(fn () => applyNormalization($fixture))->toThrow(RuntimeException::class, 'Injected normalization failure');
    expect($fixture['variants'][1]->fresh()->product_id)->toBe($fixture['products'][1]->id)
        ->and($fixture['products'][1]->fresh()->trashed())->toBeFalse()
        ->and(ProductImage::query()->whereNotNull('product_variant_id')->count())->toBe(0);
});

test('rerunning an already normalized group is idempotent', function (): void {
    $fixture = normalizationGroup();
    applyNormalization($fixture);
    $before = [
        Product::query()->withTrashed()->count(),
        ProductVariant::query()->withTrashed()->count(),
        ProductImage::query()->count(),
    ];

    $result = applyNormalization($fixture);

    expect($result['groups_already_normalized'])->toBe(1)
        ->and($result['variants_reparented'])->toBe(0)
        ->and($result['images_attributed'])->toBe(0)
        ->and([
            Product::query()->withTrashed()->count(),
            ProductVariant::query()->withTrashed()->count(),
            ProductImage::query()->count(),
        ])->toBe($before);
});

test('importer rerun keeps normalized variants and galleries under the canonical product', function (): void {
    $fixture = normalizationGroup();
    applyNormalization($fixture);
    $records = [];

    foreach ($fixture['products'] as $index => $product) {
        $variant = $fixture['variants'][$index];
        $records[] = [
            'productId' => $product->external_id,
            'name' => $product->name,
            'brand' => $fixture['brand']->name,
            'url' => $product->source_url,
            'image' => $fixture['image_urls'][$product->id][0],
            'price' => $variant->price,
            'categoryPaths' => [[$fixture['category']->name]],
            'variants' => [[
                'label' => 'Color:',
                'selected' => "color-{$index}",
                'options' => ["color-{$index}"],
            ]],
            'specifications' => [],
            'images' => $fixture['image_urls'][$product->id],
            'localImages' => [],
        ];
    }

    app(ProductJsonImportService::class)->importJson(
        json_encode($records, JSON_THROW_ON_ERROR),
        offset: 0,
        limit: count($records),
        defaultWeight: 100,
    );

    foreach ($fixture['variants'] as $variant) {
        expect($variant->fresh()->product_id)->toBe($fixture['canonical']->id)
            ->and(ProductImage::query()->where('product_variant_id', $variant->id)
                ->where('product_id', $fixture['canonical']->id)->count())->toBe(2);
    }
    expect($fixture['products'][1]->fresh()->trashed())->toBeTrue();
});
