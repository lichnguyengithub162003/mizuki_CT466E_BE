<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PasswordRecoveryRequestResource extends JsonResource
{
    /** @return array<string, int> */
    public function toArray(Request $request): array
    {
        return [
            'resend_after' => (int) $this->resource['resend_after'],
            'expires_in' => (int) $this->resource['expires_in'],
        ];
    }
}
