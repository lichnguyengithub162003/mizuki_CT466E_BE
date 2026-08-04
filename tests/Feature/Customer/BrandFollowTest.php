<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\User;
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
