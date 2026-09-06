<?php

use App\Support\MediaUrl;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('catalog/products/qa/1.webp', 'image');
    Storage::disk('public')->put('catalog/brands/mizuki.png', 'logo');
});

test('it normalizes supported public media paths through the configured public disk', function (): void {
    $expected = Storage::disk('public')->url('catalog/products/qa/1.webp');

    foreach ([
        'catalog/products/qa/1.webp',
        '/storage/catalog/products/qa/1.webp',
        storage_path('app/public/catalog/products/qa/1.webp'),
        config('app.url').'/storage/catalog/products/qa/1.webp',
    ] as $path) {
        expect(app(MediaUrl::class)->resolve($path))->toBe($expected);
    }
});

test('it preserves external URLs and rejects unsafe or non-displayable paths', function (): void {
    $media = app(MediaUrl::class);

    expect($media->resolve('https://media.example.test/image.webp'))->toBe('https://media.example.test/image.webp')
        ->and($media->resolve('data:image/gif;base64,AAAA'))->toBeNull()
        ->and($media->resolve('D:\\private\\image.webp'))->toBeNull()
        ->and($media->resolve('/storage/catalog/products/missing.webp'))->toBeNull();
});

test('it resolves a real catalog brand logo before a placeholder database value', function (): void {
    expect(app(MediaUrl::class)->brandLogo(
        'https://placehold.co/300x150?text=mizuki',
        'mizuki',
        'Mizuki',
    ))->toBe(Storage::disk('public')->url('catalog/brands/mizuki.png'));
});
