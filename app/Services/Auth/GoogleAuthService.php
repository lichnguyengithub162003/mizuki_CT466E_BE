<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\SocialAccountRepository;
use App\Repositories\UserRepository;
use App\Services\BaseService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Throwable;

class GoogleAuthService extends BaseService
{
    private const PROVIDER = 'google';

    public function __construct(
        private readonly SocialiteFactory $socialite,
        private readonly UserRepository $users,
        private readonly SocialAccountRepository $socialAccounts,
    ) {
    }

    public function redirectUrl(): string
    {
        /** @var RedirectResponse $redirect */
        $redirect = $this->socialite->driver(self::PROVIDER)->redirect();

        return $redirect->getTargetUrl();
    }

    /**
     * @throws AuthenticationException
     */
    public function handleCallback(Request $request): User
    {
        try {
            $googleUser = $this->socialite->driver(self::PROVIDER)->user();
        } catch (Throwable) {
            throw new AuthenticationException('Không thể xác thực tài khoản Google!');
        }

        $user = $this->resolveCustomer($googleUser);
        $this->authenticate($user, $request);

        return $user;
    }

    /**
     * @throws AuthenticationException
     */
    private function resolveCustomer(SocialiteUser $googleUser): User
    {
        $providerUserId = trim((string) $googleUser->getId());
        $providerEmail = Str::lower(trim((string) $googleUser->getEmail()));

        if ($providerUserId === '' || $providerEmail === '' || ! $this->hasVerifiedEmail($googleUser)) {
            throw new AuthenticationException('Không thể xác thực email Google!');
        }

        $socialAccount = $this->socialAccounts->findByProviderAndProviderUserId(self::PROVIDER, $providerUserId);

        if ($socialAccount) {
            $user = $this->ensureCustomer($socialAccount->user);
            $this->socialAccounts->createOrUpdateForUser(
                user: $user,
                provider: self::PROVIDER,
                providerUserId: $providerUserId,
                providerEmail: $providerEmail,
                avatarUrl: $googleUser->getAvatar(),
            );

            return $user;
        }

        $user = $this->users->findByEmail($providerEmail);

        if ($user) {
            $user = $this->ensureCustomer($user);
        } else {
            $user = $this->users->createCustomerFromOAuth([
                'name' => $googleUser->getName() ?: Str::before($providerEmail, '@'),
                'email' => $providerEmail,
            ]);
        }

        $this->socialAccounts->createOrUpdateForUser(
            user: $user,
            provider: self::PROVIDER,
            providerUserId: $providerUserId,
            providerEmail: $providerEmail,
            avatarUrl: $googleUser->getAvatar(),
        );

        return $user;
    }

    /**
     * @throws AuthenticationException
     */
    private function ensureCustomer(User $user): User
    {
        if ($user->role !== UserRole::Customer) {
            throw new AuthenticationException('Tài khoản không có quyền đăng nhập khu vực khách hàng!');
        }

        return $user;
    }

    private function hasVerifiedEmail(SocialiteUser $googleUser): bool
    {
        if (! method_exists($googleUser, 'getRaw')) {
            return false;
        }

        $raw = $googleUser->getRaw();

        return (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
    }

    private function authenticate(User $user, Request $request): void
    {
        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
