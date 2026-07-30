<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;

class GhnAddressService
{
    public function __construct(
        private readonly GhnClient $client,
    ) {}

    /** @return list<array<string, mixed>> */
    public function getProvinces(): array
    {
        return Cache::remember(
            'ghn.provinces',
            now()->addDay(),
            fn (): array => $this->client->provinces(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function getWards(int $districtId): array
    {
        return Cache::remember(
            "ghn.wards.{$districtId}",
            now()->addDay(),
            fn (): array => $this->client->wards($districtId),
        );
    }

    /** @return list<array<string, mixed>> */
    public function getDistricts(int $provinceId): array
    {
        return Cache::remember(
            "ghn.districts.{$provinceId}",
            now()->addDay(),
            fn (): array => $this->client->districts($provinceId),
        );
    }
}
