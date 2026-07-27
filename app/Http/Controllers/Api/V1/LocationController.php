<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Shipping\GhnAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends BaseController
{
    public function __construct(
        private readonly GhnAddressService $ghn,
    ) {}

    public function provinces(Request $request): JsonResponse
    {
        $provinces = collect($this->ghn->getProvinces())->map(fn($p) => [
            'ghn_province_id' => $p['ProvinceID'],
            'name'            => $p['ProvinceName'],
        ])->values()->toArray();

        return $this->successResponseRaw(
            request: $request,
            data: $provinces,
            message: 'Lấy danh sách tỉnh/thành thành công!',
        );
    }

    public function districts(Request $request, int $provinceId): JsonResponse
    {
        $districts = collect($this->ghn->getDistricts($provinceId))->map(fn($d) => [
            'ghn_district_id' => $d['DistrictID'],
            'name'            => $d['DistrictName'],
        ])->values()->toArray();

        return $this->successResponseRaw(
            request: $request,
            data: $districts,
            message: 'Lấy danh sách quận/huyện thành công!',
        );
    }

    public function wards(Request $request, int $districtId): JsonResponse
    {
        $wards = collect($this->ghn->getWards($districtId))->map(fn($w) => [
            'ghn_ward_code' => $w['WardCode'],
            'name'          => $w['WardName'],
        ])->values()->toArray();

        return $this->successResponseRaw(
            request: $request,
            data: $wards,
            message: 'Lấy danh sách phường/xã thành công!',
        );
    }
}