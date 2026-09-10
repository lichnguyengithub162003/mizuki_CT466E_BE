<?php

namespace App\Http\Resources\Admin;

use App\Enums\StaffEmploymentStatus;
use App\Http\Resources\Concerns\SerializesMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    use SerializesMedia;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->employment_status instanceof StaffEmploymentStatus
            ? $this->employment_status
            : StaffEmploymentStatus::tryFrom((string) $this->employment_status) ?? StaffEmploymentStatus::Working;

        return [
            'id' => $this->id, 'code' => $this->staff_code, 'name' => $this->name, 'email' => $this->email,
            'phone' => $this->phone, 'avatar' => $this->mediaUrl($this->avatar),
            'avatar_rendition_url' => $this->mediaUrl($this->avatar, 'avatar'),
            'role' => $this->role->value, 'role_label' => $this->role->label(),
            'job_title' => $this->job_title,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'status' => $status->value, 'status_label' => $status->label(),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
