<?php

namespace App\Http\Resources\Customer;

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
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                'branch_type' => $this->branch->branch_type->value,
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
}
