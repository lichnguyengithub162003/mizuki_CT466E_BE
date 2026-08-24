<?php

namespace App\Http\Resources\Technician;

use App\Enums\AppointmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $timezone = (string) config('app.timezone');

        return [
            'id' => $this->id,
            'appointment_number' => $this->appointment_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer' => [
                'name' => $this->customer_name ?? $this->user?->name,
                'phone' => $this->customer_phone ?? $this->user?->phone,
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ],
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service_name,
                'duration_minutes' => $this->duration_minutes,
            ],
            'allowed_actions' => match ($this->status) {
                AppointmentStatus::Confirmed => ['start'],
                AppointmentStatus::InProgress => ['complete'],
                default => [],
            },
            'starts_at' => $this->starts_at?->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at?->setTimezone($timezone)->toIso8601String(),
            'customer_note' => $this->customer_note,
            'staff_note' => $this->staff_note,
            'completed_at' => $this->completed_at?->setTimezone($timezone)->toIso8601String(),
        ];
    }
}
