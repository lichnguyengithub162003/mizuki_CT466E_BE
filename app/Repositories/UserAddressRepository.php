<?php

namespace App\Repositories;

use App\Models\UserAddress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class UserAddressRepository extends BaseRepository
{
    public function __construct(UserAddress $model)
    {
        parent::__construct($model);
    }

    public function listByUser(int $userId): Collection
    {
        return $this->query()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }

    public function findForUser(int $userId, int $addressId): UserAddress
    {
        $address = $this->query()
            ->where('user_id', $userId)
            ->where('id', $addressId)
            ->first();

        if (! $address) {
            throw new AuthorizationException('Địa chỉ không tồn tại hoặc không thuộc về bạn');
        }

        return $address;
    }

    public function createForUser(int $userId, array $data): UserAddress
    {
        /** @var UserAddress $address */
        $address = $this->create(array_merge($data, ['user_id' => $userId]));
        return $address;
    }

    public function update(UserAddress|\Illuminate\Database\Eloquent\Model $address, array $data): UserAddress
    {
        $address->fill($data)->save();
        return $address->refresh();
    }

    public function clearDefault(int $userId): void
    {
        $this->query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    public function delete(UserAddress|\Illuminate\Database\Eloquent\Model $address): bool
    {
        return (bool) $address->delete();
    }
}
