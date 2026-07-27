<?php

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;

test('it casts branch service availability to a boolean', function (): void {
    $branchService = new BranchService(['is_available' => 1]);

    expect($branchService->is_available)->toBeTrue();
});

test('it belongs to a branch and service', function (): void {
    $branchService = new BranchService();

    expect($branchService->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($branchService->service()->getRelated())->toBeInstanceOf(Service::class);
});
