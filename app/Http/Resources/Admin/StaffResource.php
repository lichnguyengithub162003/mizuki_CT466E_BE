<?php

namespace App\Http\Resources\Admin;

use App\Enums\StaffEmploymentStatus;
use App\Enums\UserRole;
use App\Http\Resources\Concerns\SerializesMedia;
use App\Models\StaffAssignment;
use App\Models\StaffLifecycleEvent;
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

        $data = [
            'id' => $this->id,
            'code' => $this->staff_code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->mediaUrl($this->avatar),
            'avatar_rendition_url' => $this->mediaUrl($this->avatar, 'avatar'),
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'job_title' => $this->job_title,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'status' => $status->value,
            'status_label' => $status->label(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        $detailLoaded = $this->relationLoaded('staffAssignments')
            && $this->relationLoaded('staffLifecycleEvents')
            && $this->relationLoaded('currentAssignment');
        if (! $detailLoaded) {
            return $data;
        }

        $permissions = $this->staffPermissions($request);

        return [
            ...$data,
            'current_assignment' => $this->currentAssignment === null
                ? null
                : $this->assignmentData($this->currentAssignment),
            'employment_started_at' => $this->staffAssignments
                ->sortBy('effective_from')
                ->first()?->effective_from?->toISOString(),
            'employment_ended_at' => $status === StaffEmploymentStatus::Left
                    ? $this->staffAssignments->whereNotNull('effective_to')->sortByDesc('effective_to')->first()?->effective_to?->toISOString()
                    : null,
            'permissions' => $permissions,
            'allowed_actions' => collect($permissions)->filter()->keys()->values()->all(),
            'history' => [
                'assignments' => $this->staffAssignments->map(fn (StaffAssignment $assignment): array => $this->assignmentData($assignment))->values()->all(),
                'events' => $this->staffLifecycleEvents->map(fn (StaffLifecycleEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'description' => $event->description,
                    'metadata' => $event->metadata,
                    'occurred_at' => $event->occurred_at?->toISOString(),
                    'actor' => $event->actor === null ? null : [
                        'id' => $event->actor->id,
                        'name' => $event->actor->name,
                    ],
                ])->values()->all(),
            ],
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
            'reason' => $assignment->reason,
        ];
    }

    /** @return array<string, bool> */
    private function staffPermissions(Request $request): array
    {
        $actor = $request->user();
        $manageable = $actor?->role === UserRole::SuperAdmin
            || ($actor?->role === UserRole::BranchManager
                && $actor->branch_id === $this->branch_id
                && in_array($this->role, [UserRole::Cashier, UserRole::SalesStaff, UserRole::Technician], true));

        return [
            'change_assignment' => $manageable && ! $this->trashed(),
            'change_employment_status' => $manageable && ! $this->trashed(),
            'trash' => $manageable && ! $this->trashed(),
            'restore' => $manageable && $this->trashed(),
        ];
    }
}
