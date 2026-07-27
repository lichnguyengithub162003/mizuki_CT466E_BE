<?php

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('categories endpoint returns only active categories as a hierarchy', function (): void {
    $parent = Category::query()->create([
        'name' => 'Chăm sóc da',
        'slug' => 'cham-soc-da',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    Category::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Serum',
        'slug' => 'serum',
        'sort_order' => 2,
        'is_active' => true,
    ]);
    Category::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Sữa rửa mặt',
        'slug' => 'sua-rua-mat',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    Category::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Danh mục con ẩn',
        'slug' => 'danh-muc-con-an',
        'sort_order' => 3,
        'is_active' => false,
    ]);
    Category::query()->create([
        'name' => 'Danh mục cha ẩn',
        'slug' => 'danh-muc-cha-an',
        'sort_order' => 2,
        'is_active' => false,
    ]);

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Lấy danh sách danh mục thành công!')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonPath('data.0.slug', 'cham-soc-da')
        ->assertJsonCount(2, 'data.0.children')
        ->assertJsonPath('data.0.children.0.slug', 'sua-rua-mat')
        ->assertJsonPath('data.0.children.1.slug', 'serum')
        ->assertJsonMissing(['slug' => 'danh-muc-con-an'])
        ->assertJsonMissing(['slug' => 'danh-muc-cha-an'])
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});
