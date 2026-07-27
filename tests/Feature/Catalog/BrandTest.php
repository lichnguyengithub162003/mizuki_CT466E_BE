<?php

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('brands endpoint returns only active brands ordered by name', function (): void {
    Brand::query()->create([
        'name' => 'Vichy',
        'slug' => 'vichy',
        'is_active' => true,
    ]);
    Brand::query()->create([
        'name' => 'Anessa',
        'slug' => 'anessa',
        'is_active' => true,
    ]);
    Brand::query()->create([
        'name' => 'Thương hiệu ẩn',
        'slug' => 'thuong-hieu-an',
        'is_active' => false,
    ]);

    $this->getJson('/api/v1/brands')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.slug', 'anessa')
        ->assertJsonPath('data.1.slug', 'vichy')
        ->assertJsonMissing(['slug' => 'thuong-hieu-an'])
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});

test('brand detail endpoint returns storefront fields without products', function (): void {
    $brand = Brand::query()->create([
        'name' => 'La Roche-Posay',
        'slug' => 'la-roche-posay',
        'logo_url' => 'brands/logos/la-roche-posay.png',
        'banner_image' => 'brands/banners/la-roche-posay.jpg',
        'description' => 'Dược mỹ phẩm chăm sóc da.',
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/brands/la-roche-posay')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $brand->id)
        ->assertJsonPath('data.name', 'La Roche-Posay')
        ->assertJsonPath('data.slug', 'la-roche-posay')
        ->assertJsonPath('data.logo', 'brands/logos/la-roche-posay.png')
        ->assertJsonPath('data.banner_image', 'brands/banners/la-roche-posay.jpg')
        ->assertJsonPath('data.description', 'Dược mỹ phẩm chăm sóc da.')
        ->assertJsonMissingPath('data.products')
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});
