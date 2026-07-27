<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable([
    'user_id',
    'recipient_name',
    'recipient_phone',
    'province',
    'district',
    'ward',
    'hamlet',
    'address_line',
    'is_default',
    'province_code',
    'ghn_province_id',
    'ghn_district_id',
    'ghn_ward_code',
])]
class UserAddress extends Model
{
    use SoftDeletes;
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ghn_district_id' => 'integer',
            'is_default'      => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
