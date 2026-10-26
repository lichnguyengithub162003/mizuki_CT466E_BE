<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/PortalStaffSession.php';

uses(RefreshDatabase::class);

function visibleTestCategory(string $slug, ?int $parentId = null, string $status = Category::STATUS_PUBLISHED, bool $featured = false): Category
{
    return Category::query()->create([
        'name' => $slug, 'slug' => $slug, 'parent_id' => $parentId,
        'status' => $status, 'is_featured' => $featured,
    ]);
}

function discoverableTestProduct(Category $category, string $slug, bool $active = true): Product
{
    $brand = Brand::query()->firstOrCreate(['slug' => 'visibility-brand'], ['name' => 'Visibility brand', 'is_active' => true]);
    $product = Product::query()->create([
        'category_id' => $category->id, 'brand_id' => $brand->id,
        'name' => $slug, 'slug' => $slug, 'is_active' => $active,
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id, 'name' => 'Default', 'sku' => 'VIS-'.$slug,
        'price' => 100000, 'weight' => 100, 'is_active' => true,
    ]);

    return $product;
}

test('navigation includes only published categories with published ancestors and keeps empty published roots', function (): void {
    $published = visibleTestCategory('visible-root', featured: true);
    $visibleChild = visibleTestCategory('visible-child', $published->id, featured: true);
    visibleTestCategory('empty-visible-root');
    visibleTestCategory('hidden-child', $published->id, Category::STATUS_HIDDEN, true);
    $hidden = visibleTestCategory('hidden-root', status: Category::STATUS_HIDDEN, featured: true);
    visibleTestCategory('published-under-hidden', $hidden->id, featured: true);
    $draft = visibleTestCategory('draft-root', status: Category::STATUS_DRAFT, featured: true);
    visibleTestCategory('published-under-draft', $draft->id);
    $deleted = visibleTestCategory('deleted-root');
    $deleted->delete();
    $contradictory = visibleTestCategory('contradictory-root');
    DB::table('categories')->where('id', $contradictory->id)->update(['is_active' => false]);

    $response = $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(2, 'data');
    $visibleRoot = collect($response->json('data'))->firstWhere('id', $published->id);
    expect($visibleRoot)->not->toBeNull()
        ->and(collect($visibleRoot['children'])->pluck('id')->all())->toBe([$visibleChild->id]);
    $response->assertJsonMissing(['slug' => 'hidden-child'])
        ->assertJsonMissing(['slug' => 'published-under-hidden'])
        ->assertJsonMissing(['slug' => 'published-under-draft'])
        ->assertJsonMissing(['slug' => 'deleted-root'])
        ->assertJsonMissing(['slug' => 'contradictory-root'])
        ->assertJsonMissing(['slug' => 'hidden-root'])
        ->assertJsonMissing(['slug' => 'draft-root']);
});

test('active products in hidden and draft categories remain listed searched and directly accessible', function (): void {
    $hidden = visibleTestCategory('product-hidden', status: Category::STATUS_HIDDEN);
    $draft = visibleTestCategory('product-draft', status: Category::STATUS_DRAFT);
    $hiddenProduct = discoverableTestProduct($hidden, 'discover-hidden-product');
    $draftProduct = discoverableTestProduct($draft, 'discover-draft-product');
    $inactive = discoverableTestProduct($hidden, 'discover-inactive-product', false);

    $this->getJson('/api/v1/products?keyword=discover&per_page=2')
        ->assertOk()->assertJsonPath('meta.pagination.total', 2)->assertJsonCount(2, 'data')
        ->assertJsonMissing(['id' => $inactive->id]);
    $this->getJson('/api/v1/products/search?keyword=discover')
        ->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonMissing(['id' => $inactive->id]);
    $this->getJson("/api/v1/products/{$hiddenProduct->slug}")->assertOk()->assertJsonPath('data.id', $hiddenProduct->id);
    $this->getJson("/api/v1/products/{$draftProduct->slug}")->assertOk()->assertJsonPath('data.id', $draftProduct->id);
    $this->getJson("/api/v1/products/{$inactive->slug}")->assertNotFound();
    $this->getJson("/api/v1/products?category_id={$hidden->id}")
        ->assertOk()->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $hiddenProduct->id);
});

test('parent filter includes direct and all descendant products despite hidden branches without duplicates', function (): void {
    $root = visibleTestCategory('filter-root');
    $hidden = visibleTestCategory('filter-hidden-child', $root->id, Category::STATUS_HIDDEN);
    $draft = visibleTestCategory('filter-draft-grandchild', $hidden->id, Category::STATUS_DRAFT);
    $other = visibleTestCategory('filter-other');
    $direct = discoverableTestProduct($root, 'filter-direct');
    $child = discoverableTestProduct($hidden, 'filter-child');
    $grandchild = discoverableTestProduct($draft, 'filter-grandchild');
    discoverableTestProduct($other, 'filter-other-product');

    $firstPage = $this->getJson("/api/v1/products?category_id={$root->id}&sort=name&per_page=2&page=1")
        ->assertOk()->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.pagination.last_page', 2)->assertJsonCount(2, 'data');
    $secondPage = $this->getJson("/api/v1/products?category_id={$root->id}&sort=name&per_page=2&page=2")
        ->assertOk()->assertJsonCount(1, 'data');
    expect(collect($firstPage->json('data'))->concat($secondPage->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$direct->id, $child->id, $grandchild->id])->sort()->values()->all());
});

test('changing category status preserves its product assignment and admin listing still sees it', function (): void {
    $category = visibleTestCategory('status-change-category');
    $product = discoverableTestProduct($category, 'status-change-product');
    $category->update(['status' => Category::STATUS_HIDDEN]);

    expect($product->fresh()->category_id)->toBe($category->id)
        ->and($product->fresh()->is_active)->toBeTrue();
    $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/products?category_id={$category->id}")
        ->assertOk()->assertJsonPath('meta.pagination.total', 1);

    actingAsPortalUser($this, User::factory()->create(['role' => UserRole::SuperAdmin]));
    $this->getJson('/api/v1/admin/categories')->assertOk()->assertJsonFragment(['id' => $category->id]);
});
