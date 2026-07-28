<?php

use App\Enums\AppointmentStatus;
use App\Enums\BranchType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Service;
use App\Models\SkinProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $token = Str::upper(Str::random(6));
    $makeBranch = fn (string $suffix): Branch => Branch::query()->create([
        'code' => "SKIN-{$token}-{$suffix}",
        'name' => "Skin Profile Branch {$suffix}",
        'branch_type' => BranchType::Hybrid,
        'phone' => '02920000000',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);

    $this->branch = $makeBranch('A');
    $this->otherBranch = $makeBranch('B');
    $this->service = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Skin Profile Service',
        'slug' => 'skin-profile-service-'.strtolower($token),
        'duration_minutes' => 60,
        'price' => 450000,
        'is_active' => true,
    ]);
    $this->customer = User::factory()->create([
        'role' => UserRole::Customer,
        'phone' => '0901234567',
    ]);
    $this->otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
    $this->manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $this->branch->id,
    ]);
    $this->superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->technician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $this->branch->id,
    ]);
    $this->otherTechnician = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $this->branch->id,
    ]);
});

function createSkinProfileAppointment(
    User $customer,
    Branch $branch,
    Service $service,
    ?User $technician = null,
): Appointment {
    return Appointment::query()->create([
        'appointment_number' => 'APT-SKIN-'.Str::upper(Str::random(8)),
        'user_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_phone' => $customer->phone,
        'branch_id' => $branch->id,
        'service_id' => $service->id,
        'technician_id' => $technician?->id,
        'status' => AppointmentStatus::Confirmed,
        'service_name' => $service->name,
        'service_price' => $service->price,
        'duration_minutes' => $service->duration_minutes,
        'starts_at' => '2026-08-03 09:00:00',
        'ends_at' => '2026-08-03 10:00:00',
    ]);
}

function createSkinProfile(User $customer, array $overrides = []): SkinProfile
{
    return SkinProfile::query()->create(array_merge([
        'user_id' => $customer->id,
        'skin_type' => 'combination',
        'concerns' => ['mụn'],
        'sensitivity_level' => 'medium',
    ], $overrides));
}

test('unauthenticated customer skin profile access is rejected', function (): void {
    $this->getJson('/api/v1/customer/skin-profile')->assertUnauthorized();
    $this->putJson('/api/v1/customer/skin-profile', [])->assertUnauthorized();
});

test('non-customer role is rejected from customer skin profile routes', function (): void {
    $this->actingAs($this->manager);
    $this->getJson('/api/v1/customer/skin-profile')->assertForbidden();
    $this->putJson('/api/v1/customer/skin-profile', [])->assertForbidden();
});

test('customer receives successful empty representation when profile is absent', function (): void {
    $this->actingAs($this->customer)
        ->getJson('/api/v1/customer/skin-profile')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', null)
        ->assertJsonPath('data.customer_id', $this->customer->id)
        ->assertJsonPath('data.skin_type', null)
        ->assertJsonPath('data.concerns', [])
        ->assertJsonStructure([
            'data' => [
                'id', 'customer_id', 'skin_type', 'concerns', 'sensitivity_level',
                'allergies', 'current_products', 'notes', 'created_at', 'updated_at',
            ],
        ]);

    $this->assertDatabaseCount('skin_profiles', 0);
});

test('customer creates a partial skin profile', function (): void {
    $this->actingAs($this->customer)
        ->putJson('/api/v1/customer/skin-profile', [
            'skin_type' => 'oily',
            'concerns' => ['mụn'],
        ])
        ->assertOk()
        ->assertJsonPath('data.customer_id', $this->customer->id)
        ->assertJsonPath('data.skin_type', 'oily')
        ->assertJsonPath('data.concerns.0', 'mụn');

    $this->assertDatabaseHas('skin_profiles', [
        'user_id' => $this->customer->id,
        'skin_type' => 'oily',
    ]);
});

test('customer updates existing profile without creating duplicate', function (): void {
    createSkinProfile($this->customer, ['skin_type' => 'dry']);
    $this->actingAs($this->customer);

    $this->putJson('/api/v1/customer/skin-profile', [
        'skin_type' => 'sensitive',
        'notes' => 'Theo dõi độ ẩm',
    ])->assertOk()
        ->assertJsonPath('data.skin_type', 'sensitive')
        ->assertJsonPath('data.notes', 'Theo dõi độ ẩm');

    $this->assertDatabaseCount('skin_profiles', 1);
});

test('skin type and sensitivity enum validation rejects invalid values', function (array $payload, string $field): void {
    $this->actingAs($this->customer)
        ->putJson('/api/v1/customer/skin-profile', $payload)
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => [$field]]]);
})->with([
    'skin type' => [['skin_type' => 'unknown'], 'skin_type'],
    'sensitivity' => [['sensitivity_level' => 'extreme'], 'sensitivity_level'],
]);

test('concerns must be an array of non-empty bounded strings', function (array $payload): void {
    $this->actingAs($this->customer)
        ->putJson('/api/v1/customer/skin-profile', $payload)
        ->assertUnprocessable();
})->with([
    'not an array' => [['concerns' => 'mụn']],
    'empty item' => [['concerns' => ['   ']]],
    'too long item' => [['concerns' => [str_repeat('a', 101)]]],
]);

test('duplicate concerns are trimmed and normalized before persistence', function (): void {
    $this->actingAs($this->customer)
        ->putJson('/api/v1/customer/skin-profile', [
            'concerns' => ['mụn', ' mụn ', 'thâm', 'thâm'],
        ])
        ->assertOk()
        ->assertJsonPath('data.concerns', ['mụn', 'thâm']);

    expect($this->customer->skinProfile()->firstOrFail()->concerns)->toBe(['mụn', 'thâm']);
});

test('customer route ignores attempts to target another user', function (): void {
    $this->actingAs($this->customer)
        ->putJson('/api/v1/customer/skin-profile', [
            'user_id' => $this->otherCustomer->id,
            'skin_type' => 'normal',
        ])->assertOk()->assertJsonPath('data.customer_id', $this->customer->id);

    $this->assertDatabaseHas('skin_profiles', ['user_id' => $this->customer->id]);
    $this->assertDatabaseMissing('skin_profiles', ['user_id' => $this->otherCustomer->id]);
});

test('branch manager views customer with appointment in own branch', function (): void {
    createSkinProfile($this->customer);
    createSkinProfileAppointment($this->customer, $this->branch, $this->service);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/admin/customers/{$this->customer->id}/skin-profile")
        ->assertOk()
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.profile.skin_type', 'combination');
});

test('branch manager cannot view customer linked only to another branch', function (): void {
    createSkinProfile($this->customer);
    createSkinProfileAppointment($this->customer, $this->otherBranch, $this->service);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/admin/customers/{$this->customer->id}/skin-profile")
        ->assertNotFound();
});

test('branch manager cannot view a non-customer user', function (): void {
    createSkinProfileAppointment($this->customer, $this->branch, $this->service);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/admin/customers/{$this->technician->id}/skin-profile")
        ->assertNotFound();
});

test('branch manager cannot update customer skin profile', function (): void {
    createSkinProfileAppointment($this->customer, $this->branch, $this->service);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/admin/customers/{$this->customer->id}/skin-profile", [
            'skin_type' => 'dry',
        ])->assertMethodNotAllowed();
});

test('super admin views any customer profile without branch appointment', function (): void {
    createSkinProfile($this->customer);

    $this->actingAs($this->superAdmin)
        ->getJson("/api/v1/admin/customers/{$this->customer->id}/skin-profile")
        ->assertOk()
        ->assertJsonPath('data.customer.id', $this->customer->id);
});

test('technician views customer assigned to them', function (): void {
    createSkinProfile($this->customer);
    createSkinProfileAppointment(
        $this->customer,
        $this->branch,
        $this->service,
        $this->technician,
    );

    $this->actingAs($this->technician)
        ->getJson("/api/v1/technician/customers/{$this->customer->id}/skin-profile")
        ->assertOk()
        ->assertJsonPath('data.customer.id', $this->customer->id);
});

test('technician cannot view customer assigned only to another technician', function (): void {
    createSkinProfileAppointment(
        $this->customer,
        $this->branch,
        $this->service,
        $this->otherTechnician,
    );

    $this->actingAs($this->technician)
        ->getJson("/api/v1/technician/customers/{$this->customer->id}/skin-profile")
        ->assertNotFound();
});

test('technician cannot view unrelated customer', function (): void {
    $this->actingAs($this->technician)
        ->getJson("/api/v1/technician/customers/{$this->customer->id}/skin-profile")
        ->assertNotFound();
});

test('technician cannot update customer skin profile', function (): void {
    createSkinProfileAppointment(
        $this->customer,
        $this->branch,
        $this->service,
        $this->technician,
    );

    $this->actingAs($this->technician)
        ->putJson("/api/v1/technician/customers/{$this->customer->id}/skin-profile", [
            'notes' => 'Không được phép',
        ])->assertMethodNotAllowed();
});

test('database enforces one skin profile per customer', function (): void {
    createSkinProfile($this->customer);

    expect(fn () => SkinProfile::query()->create([
        'user_id' => $this->customer->id,
        'skin_type' => 'dry',
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('skin_profiles', 1);
});

test('deleting customer cascades skin profile deletion', function (): void {
    createSkinProfile($this->customer);

    $this->customer->delete();

    $this->assertDatabaseCount('skin_profiles', 0);
});
