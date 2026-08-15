<?php

use App\Models\Appointment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('it casts review rating, visibility, and moderation time', function (): void {
    $review = new Review([
        'rating' => '5',
        'is_visible' => 1,
        'moderated_at' => '2026-06-22 16:00:00',
    ]);

    expect($review->rating)->toBeInt()->toBe(5)
        ->and($review->is_visible)->toBeTrue()
        ->and($review->moderated_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it belongs to review, product, purchase, and moderation entities', function (): void {
    $review = new Review;

    expect($review->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($review->product()->getRelated())->toBeInstanceOf(Product::class)
        ->and($review->productVariant()->getRelated())->toBeInstanceOf(ProductVariant::class)
        ->and($review->orderItem()->getRelated())->toBeInstanceOf(OrderItem::class)
        ->and($review->service()->getRelated())->toBeInstanceOf(Service::class)
        ->and($review->appointment()->getRelated())->toBeInstanceOf(Appointment::class)
        ->and($review->moderatedBy()->getRelated())->toBeInstanceOf(User::class);
});

test('it accepts nullable product identity for a service review', function (): void {
    $review = new Review([
        'product_id' => null,
        'service_id' => 10,
        'appointment_id' => 20,
        'rating' => 5,
    ]);

    expect($review->product_id)->toBeNull()
        ->and($review->service_id)->toBe(10)
        ->and($review->appointment_id)->toBe(20);
});

test('service review migration adds nullable product and unique service appointment identity', function (): void {
    $productColumn = collect(Schema::getColumns('reviews'))->firstWhere('name', 'product_id');

    expect(Schema::hasColumns('reviews', ['service_id', 'appointment_id']))->toBeTrue()
        ->and($productColumn)->not->toBeNull()
        ->and($productColumn['nullable'])->toBeTrue();
});

test('service review migration rolls back and reapplies on an empty review table', function (): void {
    $migration = require database_path(
        'migrations/2026_08_15_000001_extend_reviews_for_service_reviews.php',
    );

    $migration->down();

    $productColumn = collect(Schema::getColumns('reviews'))->firstWhere('name', 'product_id');
    expect(Schema::hasColumn('reviews', 'service_id'))->toBeFalse()
        ->and(Schema::hasColumn('reviews', 'appointment_id'))->toBeFalse()
        ->and($productColumn['nullable'])->toBeFalse();

    $migration->up();

    $productColumn = collect(Schema::getColumns('reviews'))->firstWhere('name', 'product_id');
    expect(Schema::hasColumns('reviews', ['service_id', 'appointment_id']))->toBeTrue()
        ->and($productColumn['nullable'])->toBeTrue();
});

test('it supports nullable imported ownership and casts imported metadata', function (): void {
    $review = new Review([
        'user_id' => null,
        'source' => 'hasaki',
        'source_verified_purchase' => 1,
        'images' => ['https://example.test/review.jpg'],
    ]);

    expect($review->user_id)->toBeNull()
        ->and($review->source)->toBe('hasaki')
        ->and($review->source_verified_purchase)->toBeTrue()
        ->and($review->images)->toBe(['https://example.test/review.jpg'])
        ->and(class_uses_recursive(Review::class))->toContain(SoftDeletes::class);
});
