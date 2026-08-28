<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email,
            'phone' => $this->phone, 'avatar' => $this->avatar,
            'role' => $this->role->value, 'role_label' => $this->role->label(),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
