<?php

namespace App\Http\Resources\Auth;

use App\Http\Resources\Concerns\SerializesMedia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AuthenticatedUserResource extends JsonResource
{
    use SerializesMedia;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->mediaUrl($this->avatar),
            'avatar_rendition_url' => $this->mediaUrl($this->avatar, 'avatar'),
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'branch_id' => $this->branch_id,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
