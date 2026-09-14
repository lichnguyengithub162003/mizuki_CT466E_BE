<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'staff_id',
    'assignment_id',
    'actor_id',
    'event_type',
    'description',
    'metadata',
    'occurred_at',
])]
class StaffLifecycleEvent extends Model
{
    public const ACCOUNT_CREATED = 'account_created';

    public const ASSIGNMENT_CHANGED = 'assignment_changed';

    public const BRANCH_TRANSFERRED = 'branch_transferred';

    public const ROLE_CHANGED = 'role_changed';

    public const JOB_TITLE_CHANGED = 'job_title_changed';

    public const EMPLOYMENT_STATUS_CHANGED = 'employment_status_changed';

    public const RESTORED = 'restored';

    public const TRASHED = 'trashed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id')->withTrashed();
    }

    /** @return BelongsTo<StaffAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StaffAssignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
