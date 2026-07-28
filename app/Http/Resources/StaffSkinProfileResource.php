<?php

namespace App\Http\Resources;

use App\Models\SkinProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffSkinProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->skinProfile ?? new SkinProfile(['user_id' => $this->id]);

        return [
            'customer' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
            ],
            'profile' => (new SkinProfileResource($profile))->resolve($request),
        ];
    }
}
