<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pos_session_id',
    'product_variant_id',
    'product_name',
    'variant_name',
    'sku',
    'variant_attributes',
    'unit_price',
    'quantity',
])]
class PosSessionItem extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'variant_attributes' => 'array',
            'unit_price' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<PosSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
