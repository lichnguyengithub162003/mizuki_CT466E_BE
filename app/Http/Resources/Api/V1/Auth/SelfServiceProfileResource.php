<?php

namespace App\Http\Resources\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Resources\Concerns\SerializesMedia;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class SelfServiceProfileResource extends JsonResource
{
    use SerializesMedia;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->mediaUrl($this->avatar),
            'avatar_rendition_url' => $this->mediaUrl($this->avatar, 'avatar'),
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'created_at' => $this->created_at?->toISOString(),
        ];

        if ($this->role === UserRole::Customer) {
            return $profile;
        }

        return [
            ...$profile,
            'staff_code' => $this->staff_code,
            'branch_id' => $this->branch_id,
            'branch' => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ],
            'job_title' => $this->job_title,
            'work_area' => $this->currentAssignment?->work_area,
            'employment_status' => $this->employment_status?->value,
            'employment_status_label' => $this->employment_status?->label(),
            'current_assignment' => $this->currentAssignment === null
                ? null
                : $this->assignmentData($this->currentAssignment),
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentData(StaffAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'branch' => $assignment->branch === null ? null : [
                'id' => $assignment->branch->id,
                'code' => $assignment->branch->code,
                'name' => $assignment->branch->name,
            ],
            'role' => $assignment->role->value,
            'role_label' => $assignment->role->label(),
            'job_title' => $assignment->job_title,
            'work_area' => $assignment->work_area,
            'effective_from' => $assignment->effective_from?->toISOString(),
            'effective_to' => $assignment->effective_to?->toISOString(),
        ];
    }
}
