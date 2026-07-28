<?php

use App\Enums\BranchType;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\BranchInventory;
use App\Models\BranchService;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it casts branch attributes to their expected types', function (): void {
    $branch = new Branch([
        'code' => 'CT-01',
        'branch_type' => BranchType::Hybrid->value,
        'name' => 'Mizuki Cần Thơ',
        'ghn_district_id' => '916',
        'is_active' => 1,
    ]);

    expect($branch->ghn_district_id)->toBeInt()->toBe(916)
        ->and($branch->is_active)->toBeTrue()
        ->and($branch->branch_type)->toBe(BranchType::Hybrid)
        ->and($branch->supportsRetail())->toBeTrue()
        ->and($branch->supportsClinic())->toBeTrue();
});

test('it uses store as the database default branch type', function (): void {
    $branch = Branch::query()->create([
        'code' => 'DEFAULT-TYPE',
        'name' => 'Mizuki Default Type',
        'phone' => '02923888888',
        'address' => 'Ninh Kieu, Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);

    expect($branch->refresh()->branch_type)->toBe(BranchType::Store)
        ->and($branch->supportsRetail())->toBeTrue()
        ->and($branch->supportsClinic())->toBeFalse();
});

test('it has many users', function (): void {
    $branch = new Branch;

    expect($branch->users()->getRelated())->toBeInstanceOf(User::class);
});

test('it defines operational and catalog relationships', function (): void {
    $branch = new Branch;

    expect($branch->businessHours()->getRelated())->toBeInstanceOf(BranchBusinessHour::class)
        ->and($branch->inventories()->getRelated())->toBeInstanceOf(BranchInventory::class)
        ->and($branch->carts()->getRelated())->toBeInstanceOf(Cart::class)
        ->and($branch->orders()->getRelated())->toBeInstanceOf(Order::class)
        ->and($branch->branchServices()->getRelated())->toBeInstanceOf(BranchService::class)
        ->and($branch->services()->getRelated())->toBeInstanceOf(Service::class)
        ->and($branch->promotions()->getRelated())->toBeInstanceOf(Promotion::class)
        ->and($branch->appointments()->getRelated())->toBeInstanceOf(Appointment::class);
});
