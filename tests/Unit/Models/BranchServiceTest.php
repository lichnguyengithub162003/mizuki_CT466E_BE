<?php

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;

test('it casts branch service availability and capacity', function (): void {
    $branchService = new BranchService(['is_available' => 1, 'capacity' => '2']);

    expect($branchService->is_available)->toBeTrue()
        ->and($branchService->capacity)->toBeInt()->toBe(2);
});

test('it belongs to a branch and service', function (): void {
    $branchService = new BranchService;

    expect($branchService->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($branchService->service()->getRelated())->toBeInstanceOf(Service::class);
});
