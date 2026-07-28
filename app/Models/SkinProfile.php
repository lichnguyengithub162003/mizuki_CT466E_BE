<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'skin_type',
    'concerns',
    'sensitivity_level',
    'allergies',
    'current_products',
    'notes',
])]
class SkinProfile extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'concerns' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
