<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GhnAddressService
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = config('services.ghn.base_url', 'https://dev-online-gateway.ghn.vn/shiip/public-api');
        $this->token   = config('services.ghn.token', '');
    }

    public function getProvinces(): array
    {
        return Cache::remember('ghn.provinces', now()->addDay(), function () {
            $response = Http::withHeaders(['Token' => $this->token])
                ->get("{$this->baseUrl}/master-data/province");

            return $response->json('data', []);
        });
    }

    public function getWards(int $districtId): array
    {
        return Cache::remember("ghn.wards.{$districtId}", now()->addDay(), function () use ($districtId) {
            $response = Http::withHeaders(['Token' => $this->token])
                ->post("{$this->baseUrl}/master-data/ward", [
                    'district_id' => $districtId,
                ]);

            return $response->json('data', []);
        });
    }

    public function getDistricts(int $provinceId): array
    {
        return Cache::remember("ghn.districts.{$provinceId}", now()->addDay(), function () use ($provinceId) {
            $response = Http::withHeaders(['Token' => $this->token])
                ->post("{$this->baseUrl}/master-data/district", [
                    'province_id' => $provinceId,
                ]);

            return $response->json('data', []);
        });
    }
}