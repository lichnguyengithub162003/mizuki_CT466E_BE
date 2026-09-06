<?php

namespace App\Models;

use App\Enums\RefundReturnStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'refund_number',
    'order_id',
    'user_id',
    'reviewed_by_user_id',
    'wallet_transaction_id',
    'settlement_method',
    'settlement_reference',
    'origin',
    'settlement_status',
    'return_required',
    'return_status',
    'return_inspection_status',
    'return_received_at',
    'restocked_at',
    'status',
    'requested_amount',
    'approved_amount',
    'reason_type',
    'reason',
    'evidence_paths',
    'review_note',
    'reviewed_at',
    'refunded_at',
])]
class Refund extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_amount' => 'integer',
            'approved_amount' => 'integer',
            'evidence_paths' => 'array',
            'return_required' => 'boolean',
            'return_status' => RefundReturnStatus::class,
            'return_received_at' => 'datetime',
            'restocked_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
