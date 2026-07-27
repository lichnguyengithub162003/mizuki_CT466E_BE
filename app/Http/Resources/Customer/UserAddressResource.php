<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'recipient_name'  => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'province'        => $this->province,
            'district'        => $this->district,
            'ward'            => $this->ward,
            'hamlet'          => $this->hamlet,
            'address_line'    => $this->address_line,
            'is_default'      => (bool) $this->is_default,
            'ghn_province_id' => $this->ghn_province_id,
            'ghn_district_id' => $this->ghn_district_id,
            'ghn_ward_code'   => $this->ghn_ward_code,
        ];
    }
}
