<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends BaseController
{
    public function __construct(
        private readonly BrandService $brands,
    ) {}

    public function follow(Request $request, Brand $brand): JsonResponse
    {
        return $this->successResponseRaw(
            request: $request,
            data: ['follower_count' => $this->brands->follow($brand)],
            message: 'Theo dõi thương hiệu thành công!',
        );
    }

    public function unfollow(Request $request, Brand $brand): JsonResponse
    {
        return $this->successResponseRaw(
            request: $request,
            data: ['follower_count' => $this->brands->unfollow($brand)],
            message: 'Bỏ theo dõi thương hiệu thành công!',
        );
    }
}
