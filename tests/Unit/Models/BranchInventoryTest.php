<?php

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryTransaction;
use App\Models\ProductVariant;

test('it casts stock quantities to integers', function (): void {
    $inventory = new BranchInventory([
        'quantity' => '20',
        'reserved_quantity' => '5',
        'reorder_level' => '10',
    ]);

    expect($inventory->quantity)->toBeInt()->toBe(20)
        ->and($inventory->reserved_quantity)->toBeInt()->toBe(5)
        ->and($inventory->reorder_level)->toBeInt()->toBe(10);
});

test('it defines inventory relationships', function (): void {
    $inventory = new BranchInventory();

    expect($inventory->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($inventory->productVariant()->getRelated())->toBeInstanceOf(ProductVariant::class)
        ->and($inventory->transactions()->getRelated())->toBeInstanceOf(InventoryTransaction::class);
});
