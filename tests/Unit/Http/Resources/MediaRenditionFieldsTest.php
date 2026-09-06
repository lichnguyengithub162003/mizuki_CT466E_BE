<?php

use App\Enums\UserRole;
use App\Http\Resources\Admin\BrandResource as AdminBrandResource;
use App\Http\Resources\Admin\CategoryResource as AdminCategoryResource;
use App\Http\Resources\Admin\CustomerResource;
use App\Http\Resources\Admin\ProductListResource as AdminProductListResource;
use App\Http\Resources\Admin\ProductResource as AdminProductResource;
use App\Http\Resources\Admin\StaffResource;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Http\Resources\Catalog\BrandResource as CatalogBrandResource;
use App\Http\Resources\Catalog\CategoryResource as CatalogCategoryResource;
use App\Http\Resources\Catalog\ProductDetailResource;
use App\Http\Resources\Catalog\ProductListResource;
use App\Http\Resources\Catalog\ProductSuggestResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(function (): void {
    config()->set('media.cloudinary_url', 'cloudinary://key:secret@demo-cloud');
});

function renditionReference(string $path): string
{
    return "cloudinary:mizuki/{$path}/550e8400-e29b-41d4-a716-446655440000";
}

function renditionUrl(string $path, ?string $transformation = null): string
{
    $transformations = $transformation === null ? 'f_auto/q_auto' : "{$transformation}/f_auto/q_auto";

    return "https://res.cloudinary.com/demo-cloud/image/upload/{$transformations}/mizuki/{$path}/550e8400-e29b-41d4-a716-446655440000";
}

/** @return array{0: Product, 1: string} */
function productWithRenditionRelations(): array
{
    $path = 'products/42/gallery';
    $category = new Category(['name' => 'Skin care', 'slug' => 'skin-care']);
    $category->id = 3;
    $brand = new Brand(['name' => 'Mizuki', 'slug' => 'mizuki', 'logo_url' => renditionReference('brands/7/logo')]);
    $brand->id = 7;
    $brand->setAttribute('active_product_count', 1);
    $brand->setAttribute('average_rating', 0);
    $brand->setAttribute('review_count', 0);
    $brand->setAttribute('follower_count', 0);
    $brand->setAttribute('is_following', false);

    $variant = new ProductVariant([
        'name' => 'Default',
        'sku' => 'RENDITION-DEFAULT',
        'price' => 100000,
        'sale_price' => null,
        'weight' => 100,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $variant->id = 9;
    $variant->setRelation('inventories', collect());

    $image = new ProductImage([
        'image_url' => renditionReference($path),
        'alt_text' => 'Product image',
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    $image->id = 11;

    $product = new Product([
        'name' => 'Rendition product',
        'slug' => 'rendition-product',
        'is_active' => true,
        'external_rating' => 0,
        'external_review_count' => 0,
    ]);
    $product->id = 42;
    $product->setAttribute('minimum_price', 100000);
    $product->setRelation('category', $category);
    $product->setRelation('brand', $brand);
    $product->setRelation('variants', collect([$variant]));
    $product->setRelation('images', collect([$image]));
    $product->setRelation('questions', collect());

    return [$product, $path];
}

test('product listing keeps base image aliases and exposes card and thumb renditions', function (): void {
    [$product, $path] = productWithRenditionRelations();

    $payload = (new ProductListResource($product))->toArray(new Request);

    expect($payload['primary_image'])->toBe(renditionUrl($path))
        ->and($payload['primary_image_url'])->toBe(renditionUrl($path))
        ->and($payload['primary_image_card_url'])->toBe(renditionUrl($path, 'c_fill,g_auto,h_480,w_480'))
        ->and($payload['primary_image_thumb_url'])->toBe(renditionUrl($path, 'c_fill,g_auto,h_160,w_160'));
});

test('search and admin product lists expose thumb from the unchanged primary image reference', function (): void {
    [$product, $path] = productWithRenditionRelations();

    $searchPayload = (new ProductSuggestResource($product))->toArray(new Request);
    $adminListPayload = (new AdminProductListResource($product))->toArray(new Request);
    $adminDetailPayload = (new AdminProductResource($product))->toArray(new Request);

    expect($searchPayload['primary_image_url'])->toBe(renditionUrl($path))
        ->and($searchPayload['primary_image_thumb_url'])
        ->toBe(renditionUrl($path, 'c_fill,g_auto,h_160,w_160'))
        ->and($adminListPayload['image_url'])->toBe(renditionUrl($path))
        ->and($adminListPayload['primary_image_thumb_url'])
        ->toBe(renditionUrl($path, 'c_fill,g_auto,h_160,w_160'))
        ->and($adminDetailPayload)->not->toHaveKey('primary_image_thumb_url');
});

test('search and admin product list thumbnails preserve safe fallbacks and reject non-public references', function (): void {
    [$product] = productWithRenditionRelations();
    $image = $product->images->first();

    foreach (['https://cdn.example.test/product.jpg', 'products/legacy-product.jpg'] as $reference) {
        $image->image_url = $reference;

        $searchPayload = (new ProductSuggestResource($product))->toArray(new Request);
        $adminPayload = (new AdminProductListResource($product))->toArray(new Request);

        expect($searchPayload['primary_image_thumb_url'])->toBe($searchPayload['primary_image_url'])
            ->and($adminPayload['primary_image_thumb_url'])->toBe($adminPayload['image_url']);
    }

    foreach (['private/refunds/1/evidence.jpg', 'staging/1/token/image.jpg'] as $reference) {
        $image->image_url = $reference;

        expect((new ProductSuggestResource($product))->toArray(new Request)['primary_image_thumb_url'])->toBeNull()
            ->and((new AdminProductListResource($product))->toArray(new Request)['primary_image_thumb_url'])->toBeNull();
    }
});

test('product detail keeps image URL and exposes thumb detail and embedded brand renditions', function (): void {
    [$product, $path] = productWithRenditionRelations();

    $payload = (new ProductDetailResource($product))->toArray(new Request);
    $image = $payload['images'][0];

    expect($image['image_url'])->toBe(renditionUrl($path))
        ->and($image['thumb_url'])->toBe(renditionUrl($path, 'c_fill,g_auto,h_160,w_160'))
        ->and($image['detail_url'])->toBe(renditionUrl($path, 'c_limit,h_1200,w_1200'))
        ->and($payload['brand']['logo_url'])->toBe(renditionUrl('brands/7/logo'))
        ->and($payload['brand']['logo_rendition_url'])
        ->toBe(renditionUrl('brands/7/logo', 'c_fit,h_160,w_320'));
});

test('brand resources preserve aliases and expose logo and banner renditions', function (): void {
    $brand = new Brand([
        'name' => 'Mizuki',
        'slug' => 'mizuki',
        'logo_url' => renditionReference('brands/7/logo'),
        'banner_image' => renditionReference('brands/7/banner'),
        'is_active' => true,
    ]);
    $brand->id = 7;

    foreach ([new CatalogBrandResource($brand), new AdminBrandResource($brand)] as $resource) {
        $payload = $resource->toArray(new Request);

        expect($payload['logo_url'])->toBe(renditionUrl('brands/7/logo'))
            ->and($payload['banner_image'])->toBe(renditionUrl('brands/7/banner'))
            ->and($payload['logo_rendition_url'])->toBe(renditionUrl('brands/7/logo', 'c_fit,h_160,w_320'))
            ->and($payload['banner_rendition_url'])
            ->toBe(renditionUrl('brands/7/banner', 'c_fill,g_auto,h_600,w_1600'));
    }

    expect((new CatalogBrandResource($brand))->toArray(new Request)['logo'])
        ->toBe(renditionUrl('brands/7/logo'));
});

test('category resources transform their existing image source without changing ownership', function (): void {
    $catalogCategory = new Category(['name' => 'Skin care', 'slug' => 'skin-care']);
    $catalogCategory->id = 3;
    $catalogCategory->setAttribute('thumbnail_url', renditionReference('products/42/gallery'));
    $catalogCategory->setRelation('children', collect());

    $catalogPayload = (new CatalogCategoryResource($catalogCategory))->toArray(new Request);
    expect($catalogPayload['thumbnail_url'])->toBe(renditionUrl('products/42/gallery'))
        ->and($catalogPayload['image_rendition_url'])
        ->toBe(renditionUrl('products/42/gallery', 'c_fill,g_auto,h_640,w_640'));

    $adminCategory = new Category([
        'name' => 'Skin care',
        'slug' => 'skin-care',
        'image_url' => renditionReference('categories/3'),
        'is_active' => true,
    ]);
    $adminCategory->id = 3;
    $adminPayload = (new AdminCategoryResource($adminCategory))->toArray(new Request);
    expect($adminPayload['image_url'])->toBe(renditionUrl('categories/3'))
        ->and($adminPayload['image_rendition_url'])
        ->toBe(renditionUrl('categories/3', 'c_fill,g_auto,h_640,w_640'));
});

test('existing avatar resources expose the avatar rendition and reject staging references', function (): void {
    $user = new User([
        'name' => 'QA User',
        'email' => 'qa@example.test',
        'role' => UserRole::SuperAdmin,
        'avatar' => renditionReference('users/29/avatar'),
    ]);
    $user->id = 29;

    foreach ([new AuthenticatedUserResource($user), new StaffResource($user), new CustomerResource($user)] as $resource) {
        $payload = $resource->toArray(new Request);

        expect($payload['avatar'])->toBe(renditionUrl('users/29/avatar'))
            ->and($payload['avatar_rendition_url'])
            ->toBe(renditionUrl('users/29/avatar', 'c_fill,g_auto:faces,h_256,w_256'));
    }

    $user->avatar = 'staging/29/token/file.jpg';
    $payload = (new AuthenticatedUserResource($user))->toArray(new Request);
    expect($payload['avatar'])->toBeNull()
        ->and($payload['avatar_rendition_url'])->toBeNull();
});
