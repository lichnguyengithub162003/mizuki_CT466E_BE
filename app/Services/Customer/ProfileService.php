<?php

namespace App\Services\Customer;

use App\Models\User;
use App\Models\UserAddress;
use App\Repositories\UserRepository;
use App\Repositories\UserAddressRepository;
use App\Services\BaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileService extends BaseService
{
    public function __construct(
        private readonly UserRepository        $users,
        private readonly UserAddressRepository $addresses,
    ) {}

    public function updateProfile(User $user, array $data): User
    {
        return $this->users->updateProfile($user, $data);
    }

    public function uploadAvatar(User $user, UploadedFile $avatar): User
    {
        $oldAvatar = $user->avatar;
        $avatarPath = $avatar->store('avatars', 'public');

        if ($oldAvatar && Storage::disk('public')->exists($oldAvatar)) {
            Storage::disk('public')->delete($oldAvatar);
        }

        return $this->users->updateAvatar($user, $avatarPath);
    }

    public function changePassword(User $user, array $data): void
    {
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new \InvalidArgumentException('Mật khẩu hiện tại không đúng!');
        }

        $this->users->updatePassword($user, $data['password']);
    }

    public function listAddresses(User $user)
    {
        return $this->addresses->listByUser($user->id);
    }

    public function storeAddress(User $user, array $data): UserAddress
    {
        if (! empty($data['is_default'])) {
            $this->addresses->clearDefault($user->id);
        }

        return $this->addresses->createForUser($user->id, $data);
    }

    public function updateAddress(User $user, int $addressId, array $data): UserAddress
    {
        $address = $this->addresses->findForUser($user->id, $addressId);

        if (! empty($data['is_default'])) {
            $this->addresses->clearDefault($user->id);
        }

        return $this->addresses->update($address, $data);
    }

    public function deleteAddress(User $user, int $addressId): void
    {
        $address = $this->addresses->findForUser($user->id, $addressId);
        $this->addresses->delete($address);
    }

    public function setDefaultAddress(User $user, int $addressId): UserAddress
    {
        $address = $this->addresses->findForUser($user->id, $addressId);
        $this->addresses->clearDefault($user->id);
        return $this->addresses->update($address, ['is_default' => true]);
    }
}
