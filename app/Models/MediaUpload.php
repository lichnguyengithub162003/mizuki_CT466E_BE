<?php

namespace App\Models;

use App\Enums\MediaUploadStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'upload_token',
    'uploaded_by_user_id',
    'staging_key',
    'mime_type',
    'extension',
    'bytes',
    'width',
    'height',
    'original_name',
    'status',
    'expires_at',
    'promoted_at',
    'final_key',
])]
class MediaUpload extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MediaUploadStatus::class,
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'expires_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
