<?php

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{appointment: Appointment, service: Service, branch: Branch} */
function createServiceReviewAppointment(
    ?User $customer,
    AppointmentStatus $status = AppointmentStatus::Completed,
    ?Service $service = null,
): array {
    $token = Str::upper(Str::random(10));
    $branch = Branch::query()->create([
        'code' => "SR-{$token}",
        'name' => "Service review branch {$token}",
        'phone' => '02920000000',
        'address' => 'Cần Thơ',
        'province_code' => '92',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '22001',
        'is_active' => true,
    ]);
    $service ??= Service::query()->create([
        'category' => 'skin_care',
        'name' => "Service review {$token}",
        'slug' => 'service-review-'.Str::lower($token),
        'duration_minutes' => 60,
        'price' => 450_000,
        'is_active' => true,
    ]);
    $appointment = Appointment::query()->create([
        'appointment_number' => "SR-APT-{$token}",
        'user_id' => $customer?->id,
        'customer_name' => $customer?->name ?? 'Khách vãng lai',
        'customer_phone' => '0901234567',
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'status' => $status,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => $service->duration_minutes,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'completed_at' => $status === AppointmentStatus::Completed ? now()->subHour() : null,
    ]);

    return compact('appointment', 'service', 'branch');
}

function createProductOnlyReview(User $customer): Review
{
    $token = Str::lower(Str::random(10));
    $category = Category::query()->create([
        'name' => "Category {$token}",
        'slug' => "category-{$token}",
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => "Brand {$token}",
        'slug' => "brand-{$token}",
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => "Product {$token}",
        'slug' => "product-{$token}",
        'is_active' => true,
    ]);

    return Review::query()->create([
        'user_id' => $customer->id,
        'product_id' => $product->id,
        'rating' => 4,
        'is_visible' => true,
    ]);
}

test('customer reviews own completed appointment with backend derived service identity', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/service-reviews', [
            'appointment_id' => $booking['appointment']->id,
            'rating' => 5,
            'title' => 'Chăm sóc tốt',
            'comment' => 'Kỹ thuật viên rất tận tâm.',
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.service.id', $booking['service']->id)
        ->assertJsonPath('data.appointment_id', $booking['appointment']->id)
        ->assertJsonPath('data.verified_service', true)
        ->assertJsonPath('message', 'Đánh giá dịch vụ thành công!');

    $this->assertDatabaseHas('reviews', [
        'user_id' => $customer->id,
        'product_id' => null,
        'service_id' => $booking['service']->id,
        'appointment_id' => $booking['appointment']->id,
        'rating' => 5,
        'source' => null,
    ]);
});

test('customer cannot review another customer or walk in appointment', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $other = User::factory()->create(['role' => UserRole::Customer]);
    $otherBooking = createServiceReviewAppointment($other);
    $walkIn = createServiceReviewAppointment(null);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/service-reviews', [
            'appointment_id' => $otherBooking['appointment']->id,
            'rating' => 5,
        ])->assertNotFound();
    $this->postJson('/api/v1/customer/service-reviews', [
        'appointment_id' => $walkIn['appointment']->id,
        'rating' => 5,
    ])->assertNotFound();

    $this->assertDatabaseCount('reviews', 0);
});

test('only completed appointments are eligible for a service review', function (AppointmentStatus $status): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer, $status);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/service-reviews', [
            'appointment_id' => $booking['appointment']->id,
            'rating' => 5,
        ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.appointment_id.0',
            'Lịch hẹn chưa đủ điều kiện đánh giá',
        );
})->with([
    'pending' => AppointmentStatus::Pending,
    'confirmed' => AppointmentStatus::Confirmed,
    'in progress' => AppointmentStatus::InProgress,
    'cancelled' => AppointmentStatus::Cancelled,
    'no show' => AppointmentStatus::NoShow,
]);

test('an appointment can only be reviewed once', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer);
    $this->actingAs($customer);

    $payload = ['appointment_id' => $booking['appointment']->id, 'rating' => 5];
    $this->postJson('/api/v1/customer/service-reviews', $payload)->assertCreated();
    $this->postJson('/api/v1/customer/service-reviews', $payload)
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.appointment_id.0',
            'Lịch hẹn này đã được đánh giá',
        );

    $this->assertDatabaseCount('reviews', 1);
});

test('customer may review the same service after two completed appointments', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $first = createServiceReviewAppointment($customer);
    $second = createServiceReviewAppointment($customer, service: $first['service']);
    $this->actingAs($customer);

    $this->postJson('/api/v1/customer/service-reviews', [
        'appointment_id' => $first['appointment']->id,
        'rating' => 5,
    ])->assertCreated();
    $this->postJson('/api/v1/customer/service-reviews', [
        'appointment_id' => $second['appointment']->id,
        'rating' => 4,
    ])->assertCreated();

    expect(Review::query()->where('service_id', $first['service']->id)->count())->toBe(2);
});

test('service review validates rating and prohibits forged identity fields', function (mixed $rating): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer);

    $this->actingAs($customer)
        ->postJson('/api/v1/customer/service-reviews', [
            'appointment_id' => $booking['appointment']->id,
            'rating' => $rating,
            'user_id' => User::factory()->create()->id,
            'service_id' => $booking['service']->id,
            'product_id' => 1,
            'product_variant_id' => 1,
            'order_item_id' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => [
            'rating', 'user_id', 'service_id', 'product_id', 'product_variant_id', 'order_item_id',
        ]]]);
})->with([
    'below minimum' => 0,
    'above maximum' => 6,
    'not integer' => 2.5,
]);

test('customer updates only mutable fields on own service review', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer);
    $review = Review::query()->create([
        'user_id' => $customer->id,
        'product_id' => null,
        'service_id' => $booking['service']->id,
        'appointment_id' => $booking['appointment']->id,
        'rating' => 3,
        'is_visible' => true,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/v1/customer/service-reviews/{$review->id}", [
            'rating' => 5,
            'title' => 'Đã cập nhật',
            'comment' => 'Trải nghiệm rất tốt.',
        ])
        ->assertOk()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.verified_service', true)
        ->assertJsonPath('message', 'Cập nhật đánh giá dịch vụ thành công!');

    expect($review->refresh()->rating)->toBe(5)
        ->and($review->service_id)->toBe($booking['service']->id)
        ->and($review->appointment_id)->toBe($booking['appointment']->id);
});

test('customer cannot update another customer service review', function (): void {
    $owner = User::factory()->create(['role' => UserRole::Customer]);
    $attacker = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($owner);
    $review = Review::query()->create([
        'user_id' => $owner->id,
        'product_id' => null,
        'service_id' => $booking['service']->id,
        'appointment_id' => $booking['appointment']->id,
        'rating' => 5,
        'is_visible' => true,
    ]);

    $this->actingAs($attacker)
        ->patchJson("/api/v1/customer/service-reviews/{$review->id}", ['rating' => 1])
        ->assertForbidden();

    expect($review->refresh()->rating)->toBe(5);
});

test('service review update cannot alter identity or convert product review', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer);
    $review = Review::query()->create([
        'user_id' => $customer->id,
        'product_id' => null,
        'service_id' => $booking['service']->id,
        'appointment_id' => $booking['appointment']->id,
        'rating' => 4,
        'is_visible' => true,
    ]);
    $productReview = createProductOnlyReview($customer);
    $this->actingAs($customer);

    $this->patchJson("/api/v1/customer/service-reviews/{$review->id}", [
        'appointment_id' => $booking['appointment']->id + 1,
        'service_id' => $booking['service']->id + 1,
        'product_id' => $productReview->product_id,
    ])->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['appointment_id', 'service_id', 'product_id']]]);

    $this->patchJson("/api/v1/customer/service-reviews/{$productReview->id}", ['rating' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.review.0', 'Đánh giá không thuộc dịch vụ');
    $this->patchJson("/api/v1/customer/reviews/{$review->id}", ['rating' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.review.0', 'Đánh giá không thuộc sản phẩm');
});

test('imported service review cannot be modified by customer', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer);
    $review = Review::query()->create([
        'source' => 'hasaki',
        'source_key' => hash('sha256', Str::random()),
        'user_id' => $customer->id,
        'product_id' => null,
        'service_id' => $booking['service']->id,
        'appointment_id' => $booking['appointment']->id,
        'rating' => 5,
        'is_visible' => true,
    ]);

    $this->actingAs($customer)
        ->patchJson("/api/v1/customer/service-reviews/{$review->id}", ['rating' => 1])
        ->assertForbidden();
});

test('guest and non customer roles cannot create service reviews', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $booking = createServiceReviewAppointment($customer);
    $payload = ['appointment_id' => $booking['appointment']->id, 'rating' => 5];

    $this->postJson('/api/v1/customer/service-reviews', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
        ->postJson('/api/v1/customer/service-reviews', $payload)
        ->assertForbidden();
});
