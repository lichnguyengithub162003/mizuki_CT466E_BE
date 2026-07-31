<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'email',
    'otp_hash',
    'otp_expires_at',
    'failed_attempts',
    'resend_available_at',
    'otp_verified_at',
    'otp_consumed_at',
    'verification_token_hash',
    'verification_expires_at',
    'token_consumed_at',
])]
class PasswordRecoveryChallenge extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'immutable_datetime',
            'failed_attempts' => 'integer',
            'resend_available_at' => 'immutable_datetime',
            'otp_verified_at' => 'immutable_datetime',
            'otp_consumed_at' => 'immutable_datetime',
            'verification_expires_at' => 'immutable_datetime',
            'token_consumed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
