<?php

use App\Models\Branch;
use App\Models\BranchBusinessHour;

test('it casts weekday and closed state to their expected types', function (): void {
    $businessHour = new BranchBusinessHour([
        'weekday' => '1',
        'is_closed' => 0,
    ]);

    expect($businessHour->weekday)->toBeInt()->toBe(1)
        ->and($businessHour->is_closed)->toBeFalse();
});

test('it belongs to a branch', function (): void {
    $businessHour = new BranchBusinessHour();

    expect($businessHour->branch()->getRelated())->toBeInstanceOf(Branch::class);
});
