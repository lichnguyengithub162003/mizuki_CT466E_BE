<?php

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{service: Service, appointment: Appointment, customer: User} */
function createPublicServiceReviewFixture(int $rating = 5): array
{
    $token = Str::upper(Str::random(10));
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => "PUBLIC-SR-{$token}",
        'name' => "Public review branch {$token}",
        'phone' => '02920000000',
        'address' => 'Cần Thơ',
        'province_code' => '92',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '22001',
        'is_active' => true,
    ]);
    $service = Service::query()->create([
        'category' => 'skin_care',
        'name' => "Public review service {$token}",
        'slug' => 'public-review-service-'.Str::lower($token),
        'duration_minutes' => 60,
        'price' => 450_000,
        'is_active' => true,
    ]);
    $appointment = Appointment::query()->create([
        'appointment_number' => "PUBLIC-SR-APT-{$token}",
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'status' => AppointmentStatus::Completed,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => 60,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'completed_at' => now()->subHour(),
    ]);
    Review::query()->create([
        'user_id' => $customer->id,
        'product_id' => null,
        'service_id' => $service->id,
        'appointment_id' => $appointment->id,
        'rating' => $rating,
        'title' => 'Dịch vụ tốt',
        'comment' => 'Trải nghiệm chăm sóc da tốt.',
        'is_visible' => true,
    ]);

    return compact('service', 'appointment', 'customer');
}

test('public service reviews return summary pagination and safe verified items', function (): void {
    $fixture = createPublicServiceReviewFixture();

    $response = $this->getJson("/api/v1/services/{$fixture['service']->slug}/reviews")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.service.id', $fixture['service']->id)
        ->assertJsonPath('data.summary.average_rating', 5)
        ->assertJsonPath('data.summary.total_reviews', 1)
        ->assertJsonPath('data.summary.verified_service_reviews_count', 1)
        ->assertJsonPath('data.reviews.0.customer.id', $fixture['customer']->id)
        ->assertJsonPath('data.reviews.0.rating', 5)
        ->assertJsonPath('data.reviews.0.verified_service', true)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('message', 'Lấy đánh giá dịch vụ thành công!');

    expect($response->json('data.reviews.0'))
        ->not->toHaveKeys(['user_id', 'appointment_id', 'service_id', 'source']);
});

test('public listing includes only visible native reviews for the requested service', function (): void {
    $fixture = createPublicServiceReviewFixture(5);
    $other = createPublicServiceReviewFixture(4);
    $hiddenAppointment = Appointment::query()->create([
        'appointment_number' => 'HIDDEN-'.Str::upper(Str::random(10)),
        'user_id' => $fixture['customer']->id,
        'branch_id' => $fixture['appointment']->branch_id,
        'service_id' => $fixture['service']->id,
        'status' => AppointmentStatus::Completed,
        'service_name' => $fixture['service']->name,
        'service_price' => $fixture['service']->price,
        'duration_minutes' => 60,
        'starts_at' => now()->subHours(4),
        'ends_at' => now()->subHours(3),
    ]);
    Review::query()->create([
        'user_id' => $fixture['customer']->id,
        'product_id' => null,
        'service_id' => $fixture['service']->id,
        'appointment_id' => $hiddenAppointment->id,
        'rating' => 1,
        'is_visible' => false,
    ]);
    Review::query()->create([
        'source' => 'hasaki',
        'source_key' => hash('sha256', Str::random()),
        'user_id' => null,
        'product_id' => null,
        'service_id' => $fixture['service']->id,
        'appointment_id' => null,
        'rating' => 2,
        'is_visible' => true,
    ]);

    $this->getJson("/api/v1/services/{$fixture['service']->id}/reviews")
        ->assertOk()
        ->assertJsonPath('data.summary.total_reviews', 1)
        ->assertJsonCount(1, 'data.reviews')
        ->assertJsonPath('data.reviews.0.rating', 5);

    $this->getJson("/api/v1/services/{$other['service']->id}/reviews")
        ->assertOk()
        ->assertJsonPath('data.summary.total_reviews', 1)
        ->assertJsonPath('data.reviews.0.rating', 4);
});

test('verified service is derived from matching completed appointment ownership', function (): void {
    $fixture = createPublicServiceReviewFixture();
    $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
    $invalidAppointment = Appointment::query()->create([
        'appointment_number' => 'MISMATCH-'.Str::upper(Str::random(10)),
        'user_id' => $fixture['customer']->id,
        'branch_id' => $fixture['appointment']->branch_id,
        'service_id' => $fixture['service']->id,
        'status' => AppointmentStatus::Completed,
        'service_name' => $fixture['service']->name,
        'service_price' => $fixture['service']->price,
        'duration_minutes' => 60,
        'starts_at' => now()->subHours(4),
        'ends_at' => now()->subHours(3),
    ]);
    Review::query()->create([
        'user_id' => $otherCustomer->id,
        'product_id' => null,
        'service_id' => $fixture['service']->id,
        'appointment_id' => $invalidAppointment->id,
        'rating' => 3,
        'is_visible' => true,
    ]);

    $this->getJson("/api/v1/services/{$fixture['service']->id}/reviews?verified_service=true")
        ->assertOk()
        ->assertJsonCount(1, 'data.reviews')
        ->assertJsonPath('data.reviews.0.verified_service', true);
    $this->getJson("/api/v1/services/{$fixture['service']->id}/reviews?verified_service=false")
        ->assertOk()
        ->assertJsonCount(1, 'data.reviews')
        ->assertJsonPath('data.reviews.0.verified_service', false);
});

test('public service review endpoint validates filters and requires active service', function (): void {
    $fixture = createPublicServiceReviewFixture();
    $fixture['service']->update(['is_active' => false]);

    $this->getJson("/api/v1/services/{$fixture['service']->id}/reviews")
        ->assertNotFound()
        ->assertJsonPath('message', 'Không tìm thấy dịch vụ');

    $fixture['service']->update(['is_active' => true]);
    $this->getJson("/api/v1/services/{$fixture['service']->id}/reviews?rating=6")
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['rating']]]);
});
