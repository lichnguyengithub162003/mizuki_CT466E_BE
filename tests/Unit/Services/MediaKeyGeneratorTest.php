<?php

use App\Services\Media\MediaKeyGenerator;

test('it generates canonical public entity keys with normalized extensions', function (): void {
    $keys = app(MediaKeyGenerator::class);

    expect($keys->productGallery(125, '.WEBP'))
        ->toMatch('~^public/products/125/gallery/[0-9a-f-]{36}\.webp$~')
        ->and($keys->avatar(9, 'jpeg'))
        ->toMatch('~^public/users/9/avatar/[0-9a-f-]{36}\.jpg$~')
        ->and($keys->questionMedia(31, 'png'))
        ->toMatch('~^public/questions/31/media/[0-9a-f-]{36}\.png$~');
});

test('it generates refund image video and staging keys', function (): void {
    $keys = app(MediaKeyGenerator::class);

    expect($keys->refundImage(44, 'png'))
        ->toMatch('~^private/refunds/44/evidence/images/[0-9a-f-]{36}\.png$~')
        ->and($keys->refundVideo(44, 'MP4'))
        ->toMatch('~^private/refunds/44/evidence/videos/[0-9a-f-]{36}\.mp4$~')
        ->and($keys->staging(7, 'upload_token-1', 'jpg'))
        ->toMatch('~^staging/7/upload_token-1/[0-9a-f-]{36}\.jpg$~');
});

test('it rejects traversal unsafe segments and unsupported prefixes', function (string $key): void {
    app(MediaKeyGenerator::class)->assertValid($key);
})->with([
    '../public/products/1/gallery/file.jpg',
    'public/products/1/../private/file.jpg',
    'public\\products\\1\\file.jpg',
    '/public/products/1/file.jpg',
    'https://cdn.example.test/public/file.jpg',
    'other/products/1/file.jpg',
])->throws(InvalidArgumentException::class);

test('it rejects traversal in staging tokens', function (): void {
    app(MediaKeyGenerator::class)->staging(1, '../escape', 'jpg');
})->throws(InvalidArgumentException::class);
