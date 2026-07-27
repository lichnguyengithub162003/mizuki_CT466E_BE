<?php

namespace App\Repositories;

use App\Models\SocialAccount;
use App\Models\User;

/**
 * @extends BaseRepository<SocialAccount>
 */
class SocialAccountRepository extends BaseRepository
{
    public function __construct(SocialAccount $model)
    {
        parent::__construct($model);
    }

    public function findByProviderAndProviderUserId(string $provider, string $providerUserId): ?SocialAccount
    {
        /** @var SocialAccount|null $socialAccount */
        $socialAccount = $this->query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        return $socialAccount;
    }

    public function findByUserAndProvider(User $user, string $provider): ?SocialAccount
    {
        /** @var SocialAccount|null $socialAccount */
        $socialAccount = $this->query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->first();

        return $socialAccount;
    }

    public function createOrUpdateForUser(
        User $user,
        string $provider,
        string $providerUserId,
        ?string $providerEmail,
        ?string $avatarUrl,
    ): SocialAccount {
        /** @var SocialAccount $socialAccount */
        $socialAccount = $this->query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
            ],
            [
                'provider_user_id' => $providerUserId,
                'provider_email' => $providerEmail,
                'avatar_url' => $avatarUrl,
            ],
        );

        return $socialAccount->refresh();
    }
}
