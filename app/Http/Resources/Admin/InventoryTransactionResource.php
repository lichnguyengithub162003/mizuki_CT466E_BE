<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'transaction_number' => $this->transaction_number, 'type' => $this->type,
            'quantity_delta' => $this->quantity_delta, 'reserved_quantity_delta' => $this->reserved_quantity_delta,
            'quantity_after' => $this->quantity_after, 'reserved_quantity_after' => $this->reserved_quantity_after,
            'reason' => $this->note, 'reference_type' => $this->reference_type, 'reference_id' => $this->reference_id,
            'operator' => $this->whenLoaded('performedBy', fn () => $this->performedBy === null ? null : ['id' => $this->performedBy->id, 'name' => $this->performedBy->name]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
