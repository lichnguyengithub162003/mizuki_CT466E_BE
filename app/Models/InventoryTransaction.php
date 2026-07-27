<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_number',
    'branch_inventory_id',
    'performed_by_user_id',
    'type',
    'quantity_delta',
    'reserved_quantity_delta',
    'quantity_after',
    'reserved_quantity_after',
    'reference_type',
    'reference_id',
    'note',
])]
class InventoryTransaction extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'reserved_quantity_delta' => 'integer',
            'quantity_after' => 'integer',
            'reserved_quantity_after' => 'integer',
            'reference_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BranchInventory, $this>
     */
    public function branchInventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
