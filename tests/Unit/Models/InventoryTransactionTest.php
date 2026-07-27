<?php

use App\Models\BranchInventory;
use App\Models\InventoryTransaction;
use App\Models\User;

test('it is immutable and casts inventory ledger values to integers', function (): void {
    $transaction = new InventoryTransaction([
        'quantity_delta' => '-2',
        'reserved_quantity_delta' => '2',
        'quantity_after' => '18',
        'reserved_quantity_after' => '2',
        'reference_id' => '100',
    ]);

    expect($transaction->usesTimestamps())->toBeFalse()
        ->and($transaction->quantity_delta)->toBeInt()->toBe(-2)
        ->and($transaction->reserved_quantity_delta)->toBeInt()->toBe(2)
        ->and($transaction->quantity_after)->toBeInt()->toBe(18)
        ->and($transaction->reference_id)->toBeInt()->toBe(100);
});

test('it belongs to an inventory row and optional operator', function (): void {
    $transaction = new InventoryTransaction();

    expect($transaction->branchInventory()->getRelated())->toBeInstanceOf(BranchInventory::class)
        ->and($transaction->performedBy()->getRelated())->toBeInstanceOf(User::class);
});
