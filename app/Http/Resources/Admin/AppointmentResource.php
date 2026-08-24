<?php

namespace App\Http\Resources\Admin;

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
                'id' => $this->user?->id,
                'name' => $this->customer_name ?? $this->user?->name,
                'email' => $this->user?->email,
                'phone' => $this->customer_phone ?? $this->user?->phone,
                'registered' => $this->user_id !== null,
            ],
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ],
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service_name,
                'slug' => $this->service->slug,
                'price' => $this->service_price,
                'duration_minutes' => $this->duration_minutes,
            ],
            'technician' => $this->technician === null ? null : [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
            ],
            'allowed_actions' => $this->allowedActions(),
            'starts_at' => $this->starts_at?->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at?->setTimezone($timezone)->toIso8601String(),
            'customer_note' => $this->customer_note,
            'staff_note' => $this->staff_note,
            'cancelled_at' => $this->cancelled_at?->setTimezone($timezone)->toIso8601String(),
            'completed_at' => $this->completed_at?->setTimezone($timezone)->toIso8601String(),
            'created_at' => $this->created_at?->setTimezone($timezone)->toIso8601String(),
            'updated_at' => $this->updated_at?->setTimezone($timezone)->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private function allowedActions(): array
    {
        return match ($this->status) {
            AppointmentStatus::Pending => ['confirm', 'assign_technician', 'cancel'],
            AppointmentStatus::Confirmed => array_values(array_filter([
                'assign_technician',
                $this->technician_id === null ? null : 'start',
                'cancel',
            ])),
            AppointmentStatus::InProgress => ['complete'],
            default => [],
        };
    }
}
