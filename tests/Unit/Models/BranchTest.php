<?php

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

test('it casts branch attributes to their expected types', function (): void {
    $branch = new Branch([
        'code' => 'CT-01',
        'name' => 'Mizuki Cần Thơ',
        'ghn_district_id' => '916',
        'is_active' => 1,
    ]);

    expect($branch->ghn_district_id)->toBeInt()->toBe(916)
        ->and($branch->is_active)->toBeTrue();
});

test('it has many users', function (): void {
    $branch = new Branch();

    expect($branch->users()->getRelated())->toBeInstanceOf(User::class);
});

test('it defines operational and catalog relationships', function (): void {
    $branch = new Branch();

    expect($branch->businessHours()->getRelated())->toBeInstanceOf(BranchBusinessHour::class)
        ->and($branch->inventories()->getRelated())->toBeInstanceOf(BranchInventory::class)
        ->and($branch->carts()->getRelated())->toBeInstanceOf(Cart::class)
        ->and($branch->orders()->getRelated())->toBeInstanceOf(Order::class)
        ->and($branch->branchServices()->getRelated())->toBeInstanceOf(BranchService::class)
        ->and($branch->services()->getRelated())->toBeInstanceOf(Service::class)
        ->and($branch->promotions()->getRelated())->toBeInstanceOf(Promotion::class)
        ->and($branch->appointments()->getRelated())->toBeInstanceOf(Appointment::class);
});
