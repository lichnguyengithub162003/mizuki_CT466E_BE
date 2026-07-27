<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'cashier_id',
    'branch_id',
    'customer_user_id',
    'customer_name',
    'customer_phone',
    'payment_method',
    'status',
    'order_id',
    'expires_at',
    'completed_at',
])]
class PosSession extends Model
{
    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PosSessionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PosSessionItem::class);
    }
}
