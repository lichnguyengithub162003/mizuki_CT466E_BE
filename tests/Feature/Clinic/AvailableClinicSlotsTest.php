<?php

use App\Enums\AppointmentStatus;
use App\Enums\BranchType;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-28 08:00:00');
    CarbonImmutable::setTestNow('2026-07-28 08:00:00');

    $this->clinic = Branch::query()->create([
        'code' => 'SLOT-CLINIC',
        'name' => 'Slot Clinic',
        'branch_type' => BranchType::Hybrid,
        'phone' => '02920000000',
        'address' => 'Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $this->service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Sixty Minute Service',
        'slug' => 'sixty-minute-service',
        'duration_minutes' => 60,
        'price' => 450000,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    BranchService::query()->create([
        'branch_id' => $this->clinic->id,
        'service_id' => $this->service->id,
        'is_available' => true,
        'capacity' => 2,
    ]);
    BranchBusinessHour::query()->create([
        'branch_id' => $this->clinic->id,
        'weekday' => 1,
        'opens_at' => '08:00:00',
        'closes_at' => '21:00:00',
        'is_closed' => false,
    ]);

    $this->bookingDate = '2026-08-03';
    $this->slotsUrl = "/api/v1/clinics/{$this->clinic->id}/services/{$this->service->id}/slots";
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('guest receives slots within the clinic window on a stable grid', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');

    $response = $this->getJson($this->slotsUrl.'?date='.$this->bookingDate)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.date', $this->bookingDate)
        ->assertJsonPath('data.timezone', config('app.timezone'))
        ->assertJsonPath('data.service.duration_minutes', 60)
        ->assertJsonPath('data.service.capacity', 2)
        ->assertJsonStructure([
            'success',
            'data' => [
                'branch',
                'service',
                'date',
                'timezone',
                'slots' => [['start_at', 'end_at', 'available', 'remaining_capacity']],
            ],
            'message',
            'meta',
        ]);

    $slots = $response->json('data.slots');

    expect($slots)->not->toBeEmpty()
        ->and($slots[0]['start_at'])->toContain('T09:00:00+07:00')
        ->and($slots[0]['end_at'])->toContain('T10:00:00+07:00')
        ->and($slots[1]['start_at'])->toContain('T09:30:00+07:00')
        ->and($slots[array_key_last($slots)]['end_at'])->toContain('T20:00:00+07:00');

    foreach ($slots as $slot) {
        $start = CarbonImmutable::parse($slot['start_at']);
        $end = CarbonImmutable::parse($slot['end_at']);

        expect($start->format('H:i'))->toBeGreaterThanOrEqual('09:00')
            ->and($end->format('H:i'))->toBeLessThanOrEqual('20:00')
            ->and($start->diffInMinutes($end))->toBe(60.0);
    }
});

test('invalid past and more than ninety day future dates return 422', function (string $date): void {
    $this->getJson($this->slotsUrl.'?date='.$date)
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'data' => ['errors' => ['date']], 'message', 'meta']);
})->with([
    'invalid format' => ['2026/08/03'],
    'past date' => ['2026-07-27'],
    'too far future' => ['2026-10-27'],
]);

test('closed weekday returns an empty slot list', function (): void {
    BranchBusinessHour::query()
        ->where('branch_id', $this->clinic->id)
        ->where('weekday', 1)
        ->update([
            'opens_at' => null,
            'closes_at' => null,
            'is_closed' => true,
        ]);

    $this->getJson($this->slotsUrl.'?date='.$this->bookingDate)
        ->assertOk()
        ->assertJsonPath('data.slots', []);
});

test('blocking appointments reduce capacity while cancelled appointments do not', function (): void {
    $user = User::factory()->create();
    $attributes = [
        'user_id' => $user->id,
        'branch_id' => $this->clinic->id,
        'service_id' => $this->service->id,
        'service_name' => $this->service->name,
        'service_price' => $this->service->price,
        'duration_minutes' => 60,
        'starts_at' => $this->bookingDate.' 09:00:00',
        'ends_at' => $this->bookingDate.' 10:00:00',
    ];

    Appointment::query()->create($attributes + [
        'appointment_number' => 'APT-BLOCKING',
        'status' => AppointmentStatus::Pending,
    ]);
    Appointment::query()->create($attributes + [
        'appointment_number' => 'APT-CANCELLED',
        'status' => AppointmentStatus::Cancelled,
        'cancelled_at' => $this->bookingDate.' 08:00:00',
    ]);

    $this->getJson($this->slotsUrl.'?date='.$this->bookingDate)
        ->assertOk()
        ->assertJsonPath('data.slots.0.available', true)
        ->assertJsonPath('data.slots.0.remaining_capacity', 1);
});

test('a full slot remains visible and is marked unavailable', function (): void {
    $user = User::factory()->create();

    foreach ([1, 2] as $index) {
        Appointment::query()->create([
            'appointment_number' => 'APT-FULL-'.$index,
            'user_id' => $user->id,
            'branch_id' => $this->clinic->id,
            'service_id' => $this->service->id,
            'status' => AppointmentStatus::Confirmed,
            'service_name' => $this->service->name,
            'service_price' => $this->service->price,
            'duration_minutes' => 60,
            'starts_at' => $this->bookingDate.' 09:00:00',
            'ends_at' => $this->bookingDate.' 10:00:00',
        ]);
    }

    $this->getJson($this->slotsUrl.'?date='.$this->bookingDate)
        ->assertOk()
        ->assertJsonPath('data.slots.0.available', false)
        ->assertJsonPath('data.slots.0.remaining_capacity', 0);
});

test('invalid clinic service combinations return a standard 404', function (): void {
    $otherService = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Unavailable Service',
        'slug' => 'unavailable-service',
        'duration_minutes' => 30,
        'price' => 200000,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $this->getJson("/api/v1/clinics/{$this->clinic->id}/services/{$otherService->id}/slots?date={$this->bookingDate}")
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});
