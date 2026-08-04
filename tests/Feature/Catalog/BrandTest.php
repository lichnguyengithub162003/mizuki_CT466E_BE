<?php

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('brands endpoint returns only active brands ordered by name', function (): void {
    Storage::disk('public')->put('catalog/brands/vichy.png', 'logo');
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
        ->assertJsonPath('data.0.logo_url', null)
        ->assertJsonPath('data.1.slug', 'vichy')
        ->assertJsonPath('data.1.logo_url', url(Storage::disk('public')->url('catalog/brands/vichy.png')))
        ->assertJsonPath('data.1.logo', url(Storage::disk('public')->url('catalog/brands/vichy.png')))
        ->assertJsonMissing(['slug' => 'thuong-hieu-an'])
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});

test('brand detail endpoint returns storefront fields without products', function (): void {
    Storage::disk('public')->put('catalog/brands/la roche-posay.png', 'logo');
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
        ->assertJsonPath('data.logo_url', url(Storage::disk('public')->url('catalog/brands/la roche-posay.png')))
        ->assertJsonPath('data.logo', url(Storage::disk('public')->url('catalog/brands/la roche-posay.png')))
        ->assertJsonPath('data.banner_image', 'brands/banners/la-roche-posay.jpg')
        ->assertJsonPath('data.description', 'Dược mỹ phẩm chăm sóc da.')
        ->assertJsonMissingPath('data.products')
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});
