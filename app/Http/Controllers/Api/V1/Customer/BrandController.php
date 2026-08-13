<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Brand;
use App\Models\User;
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
        /** @var User $user */
        $user = $request->user();

        return $this->successResponseRaw(
            request: $request,
            data: $this->brands->follow($user, $brand),
            message: 'Theo dõi thương hiệu thành công!',
        );
    }

    public function unfollow(Request $request, Brand $brand): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponseRaw(
            request: $request,
            data: $this->brands->unfollow($user, $brand),
            message: 'Bỏ theo dõi thương hiệu thành công!',
        );
    }
}
