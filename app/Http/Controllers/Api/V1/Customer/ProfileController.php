<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Customer\ChangePasswordRequest;
use App\Http\Requests\Customer\StoreAddressRequest;
use App\Http\Requests\Customer\UpdateAddressRequest;
use App\Http\Requests\Customer\UpdateProfileRequest;
use App\Http\Requests\Customer\UploadAvatarRequest;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Http\Resources\Customer\UserAddressResource;
use App\Services\Customer\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProfileController extends BaseController
{
    public function __construct(
        private readonly ProfileService $profile,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($request->user()),
            message: 'Lấy thông tin cá nhân thành công!',
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profile->updateProfile($request->user(), $request->validated());

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($user),
            message: 'Cập nhật thông tin cá nhân thành công!',
        );
    }

    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = $this->profile->uploadAvatar($request->user(), $request->file('avatar'));

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($user),
            message: 'Cập nhật ảnh đại diện thành công!',
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->profile->changePassword($request->user(), $request->validated());

        return $this->successResponse(
            request: $request,
            resource: null,
            message: 'Đổi mật khẩu thành công!',
        );
    }

    public function indexAddress(Request $request): JsonResponse
    {
        $addresses = $this->profile->listAddresses($request->user());

        return $this->successResponse(
            request: $request,
            resource: UserAddressResource::collection($addresses),
            message: 'Lấy danh sách địa chỉ thành công!',
        );
    }

    public function storeAddress(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->profile->storeAddress($request->user(), $request->validated());

        return $this->successResponse(
            request: $request,
            resource: new UserAddressResource($address),
            message: 'Thêm địa chỉ thành công!',
            status: 201,
        );
    }

    public function updateAddress(UpdateAddressRequest $request, int $id): JsonResponse
    {
        $address = $this->profile->updateAddress($request->user(), $id, $request->validated());

        return $this->successResponse(
            request: $request,
            resource: new UserAddressResource($address),
            message: 'Cập nhật địa chỉ thành công!',
        );
    }

    public function destroyAddress(Request $request, int $id): JsonResponse
    {
        $this->profile->deleteAddress($request->user(), $id);

        return $this->successResponse(
            request: $request,
            resource: null,
            message: 'Xóa địa chỉ thành công!',
        );
    }

    public function setDefaultAddress(Request $request, int $id): JsonResponse
    {
        $address = $this->profile->setDefaultAddress($request->user(), $id);

        return $this->successResponse(
            request: $request,
            resource: new UserAddressResource($address),
            message: 'Đặt địa chỉ mặc định thành công!',
        );
    }
}
