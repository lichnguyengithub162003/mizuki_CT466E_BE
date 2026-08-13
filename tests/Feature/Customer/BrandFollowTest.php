<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\BrandFollow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function followableBrand(int $followerCount = 0): Brand
{
    return Brand::query()->create([
        'name' => 'Follow Brand',
        'slug' => 'follow-brand-'.str()->random(8),
        'follower_count' => $followerCount,
        'is_active' => true,
    ]);
}

test('customer can increment and decrement a brand follower counter', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand(12);

    $this->actingAs($customer)
        ->postJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 13);

    $this->actingAs($customer)
        ->deleteJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 12);

    expect($brand->refresh()->follower_count)->toBe(12);
});

test('unfollow uses a guarded decrement and never goes below zero', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand();

    $this->actingAs($customer)
        ->deleteJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 0);

    expect($brand->refresh()->follower_count)->toBe(0);
});

test('guest and non-customer roles cannot change brand follower counters', function (): void {
    $brand = followableBrand(5);

    $this->postJson("/api/v1/customer/brands/{$brand->id}/follow")->assertUnauthorized();
    $this->deleteJson("/api/v1/customer/brands/{$brand->id}/follow")->assertUnauthorized();

    $staff = User::factory()->create(['role' => UserRole::Cashier]);
    $this->actingAs($staff)
        ->postJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertForbidden();
    $this->actingAs($staff)
        ->deleteJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertForbidden();

    expect($brand->refresh()->follower_count)->toBe(5);
});

test('first follow persists state and duplicate follow is idempotent', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand(12);

    $this->actingAs($customer)
        ->postJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 13)
        ->assertJsonPath('data.is_following', true);

    $this->actingAs($customer)
        ->postJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 13)
        ->assertJsonPath('data.is_following', true);

    expect(BrandFollow::query()
        ->where('user_id', $customer->id)
        ->where('brand_id', $brand->id)
        ->count())->toBe(1)
        ->and($brand->refresh()->follower_count)->toBe(13);
});

test('different customers each add one follower from the existing base count', function (): void {
    $first = User::factory()->create(['role' => UserRole::Customer]);
    $second = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand(2430);

    $this->actingAs($first)->postJson("/api/v1/customer/brands/{$brand->id}/follow")->assertOk();
    $this->actingAs($second)->postJson("/api/v1/customer/brands/{$brand->id}/follow")->assertOk();

    expect($brand->refresh()->follower_count)->toBe(2432)
        ->and(BrandFollow::query()->where('brand_id', $brand->id)->count())->toBe(2);
});

test('base follower count survives an idempotent customer follow and unfollow lifecycle', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand(2430);

    $this->actingAs($customer)
        ->postJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 2431)
        ->assertJsonPath('data.is_following', true);

    $this->actingAs($customer)
        ->postJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 2431);

    $this->actingAs($customer)
        ->deleteJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 2430)
        ->assertJsonPath('data.is_following', false);

    $this->actingAs($customer)
        ->deleteJson("/api/v1/customer/brands/{$brand->id}/follow")
        ->assertOk()
        ->assertJsonPath('data.follower_count', 2430)
        ->assertJsonPath('data.is_following', false);

    expect(BrandFollow::query()->where('brand_id', $brand->id)->doesntExist())->toBeTrue();
});

test('deleting a user cascades follow state without rewriting the aggregate counter', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand(10);

    $this->actingAs($customer)->postJson("/api/v1/customer/brands/{$brand->id}/follow")->assertOk();
    $customer->delete();

    expect(BrandFollow::query()->where('brand_id', $brand->id)->doesntExist())->toBeTrue()
        ->and($brand->refresh()->follower_count)->toBe(11);
});

test('force deleting a brand cascades its follow rows', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand();

    $this->actingAs($customer)->postJson("/api/v1/customer/brands/{$brand->id}/follow")->assertOk();
    $brandId = $brand->id;
    $brand->forceDelete();

    expect(BrandFollow::query()->where('brand_id', $brandId)->doesntExist())->toBeTrue();
});

test('database unique constraint rejects duplicate customer brand relations', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $brand = followableBrand();
    $attributes = [
        'user_id' => $customer->id,
        'brand_id' => $brand->id,
    ];

    BrandFollow::query()->create($attributes);

    expect(fn () => BrandFollow::query()->create($attributes))
        ->toThrow(QueryException::class);
});
