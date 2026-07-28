<?php

namespace App\Http\Resources\Clinic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailableSlotsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'branch' => (new ClinicBranchResource($data['branch']))->resolve($request),
            'service' => (new ClinicServiceResource($data['service']))->resolve($request),
            'date' => $data['date'],
            'timezone' => $data['timezone'],
            'slots' => collect($data['slots'])->map(fn (array $slot): array => [
                'start_at' => $slot['start_at'],
                'end_at' => $slot['end_at'],
                'available' => $slot['available'],
                'remaining_capacity' => $slot['remaining_capacity'],
            ])->all(),
        ];
    }
}
