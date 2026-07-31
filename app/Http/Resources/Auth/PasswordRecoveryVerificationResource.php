<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PasswordRecoveryVerificationResource extends JsonResource
{
    /** @return array<string, int|string> */
    public function toArray(Request $request): array
    {
        return [
            'verification_token' => (string) $this->resource['verification_token'],
            'expires_in' => (int) $this->resource['expires_in'],
        ];
    }
}
