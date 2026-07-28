<?php

use App\Enums\BranchType;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest sees only active branches that support clinic', function (): void {
    $base = [
        'phone' => '02920000000',
        'address' => 'Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
    ];

    $clinic = Branch::query()->create($base + [
        'code' => 'CLINIC-A',
        'name' => 'Clinic A',
        'branch_type' => BranchType::Clinic,
        'is_active' => true,
    ]);
    $hybrid = Branch::query()->create($base + [
        'code' => 'HYBRID-A',
        'name' => 'Hybrid A',
        'branch_type' => BranchType::Hybrid,
        'is_active' => true,
    ]);
    Branch::query()->create($base + [
        'code' => 'STORE-A',
        'name' => 'Store A',
        'branch_type' => BranchType::Store,
        'is_active' => true,
    ]);
    Branch::query()->create($base + [
        'code' => 'CLINIC-OFF',
        'name' => 'Inactive Clinic',
        'branch_type' => BranchType::Clinic,
        'is_active' => false,
    ]);

    $this->getJson('/api/v1/clinics')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $clinic->id)
        ->assertJsonPath('data.1.id', $hybrid->id)
        ->assertJsonMissing(['code' => 'STORE-A'])
        ->assertJsonMissing(['code' => 'CLINIC-OFF'])
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
});

test('guest sees only active services enabled at the clinic', function (): void {
    $clinic = Branch::query()->create([
        'code' => 'CLINIC-SERVICE',
        'name' => 'Clinic Service',
        'branch_type' => BranchType::Hybrid,
        'phone' => '02920000000',
        'address' => 'Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $active = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Active Service',
        'slug' => 'active-service',
        'duration_minutes' => 60,
        'price' => 400000,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $disabled = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Disabled Service',
        'slug' => 'disabled-service',
        'duration_minutes' => 30,
        'price' => 250000,
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $inactive = Service::query()->create([
        'category' => 'skin_care',
        'name' => 'Inactive Service',
        'slug' => 'inactive-service',
        'duration_minutes' => 30,
        'price' => 200000,
        'is_active' => false,
        'sort_order' => 3,
    ]);

    BranchService::query()->create([
        'branch_id' => $clinic->id,
        'service_id' => $active->id,
        'is_available' => true,
        'capacity' => 2,
    ]);
    BranchService::query()->create([
        'branch_id' => $clinic->id,
        'service_id' => $disabled->id,
        'is_available' => false,
        'capacity' => 1,
    ]);
    BranchService::query()->create([
        'branch_id' => $clinic->id,
        'service_id' => $inactive->id,
        'is_available' => true,
        'capacity' => 1,
    ]);

    $this->getJson("/api/v1/clinics/{$clinic->id}/services")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id)
        ->assertJsonPath('data.0.duration_minutes', 60)
        ->assertJsonPath('data.0.price', 400000)
        ->assertJsonPath('data.0.capacity', 2)
        ->assertJsonPath('data.0.is_available', true)
        ->assertJsonMissing(['id' => $disabled->id])
        ->assertJsonMissing(['id' => $inactive->id]);
});

test('store-only and inactive branches return standard 404 responses for services', function (BranchType $type, bool $isActive): void {
    $branch = Branch::query()->create([
        'code' => 'INVALID-'.strtoupper($type->value).(int) $isActive,
        'name' => 'Not A Public Clinic',
        'branch_type' => $type,
        'phone' => '02920000000',
        'address' => 'Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => $isActive,
    ]);

    $this->getJson("/api/v1/clinics/{$branch->id}/services")
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'data', 'message', 'meta']);
})->with([
    'store-only' => [BranchType::Store, true],
    'inactive clinic' => [BranchType::Clinic, false],
]);
