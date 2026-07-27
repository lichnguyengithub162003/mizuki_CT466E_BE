<?php

namespace App\Repositories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

/**
 * @extends BaseRepository<User>
 */
class UserRepository extends BaseRepository
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    /**
     * @param array{name: string, email: string, password: string} $attributes
     */
    public function createCustomer(array $attributes): User
    {
        /** @var User $user */
        $user = $this->create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $attributes['password'],
            'role' => UserRole::Customer,
            'branch_id' => null,
        ]);

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        /** @var User|null $user */
        $user = $this->query()
            ->where('email', $email)
            ->first();

        return $user;
    }

    public function findCustomerByPhone(string $phone): ?User
    {
        /** @var User|null $user */
        $user = $this->query()
            ->where('phone', $phone)
            ->where('role', UserRole::Customer)
            ->first();

        return $user;
    }

    /**
     * @param array{name: string, email: string} $attributes
     */
    public function createCustomerFromOAuth(array $attributes): User
    {
        /** @var User $user */
        $user = $this->create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => Str::password(40),
            'role' => UserRole::Customer,
            'branch_id' => null,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return $user->refresh();
    }

    public function updateProfile(User $user, array $data): User
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('phone', $data)) {
            $attributes['phone'] = $data['phone'];
        }

        $user->fill($attributes)->save();

        return $user->refresh();
    }

    public function updateAvatar(User $user, string $avatarPath): User
    {
        $user->forceFill([
            'avatar' => $avatarPath,
        ])->save();

        return $user->refresh();
    }

    public function updatePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => Hash::make($password)])->save();
    }
}
