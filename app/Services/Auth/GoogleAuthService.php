<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Exceptions\Auth\GoogleOAuthException;
use App\Models\User;
use App\Repositories\SocialAccountRepository;
use App\Repositories\UserRepository;
use App\Services\BaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthService extends BaseService
{
    private const PROVIDER = 'google';

    private const INTENDED_PATH_SESSION_KEY = 'google_oauth_intended_path';

    public function __construct(
        private readonly SocialiteFactory $socialite,
        private readonly UserRepository $users,
        private readonly SocialAccountRepository $socialAccounts,
    ) {}

    public function redirectUrl(Request $request): string
    {
        $this->rememberIntendedPath($request);

        /** @var RedirectResponse $redirect */
        $redirect = $this->socialite->driver(self::PROVIDER)->redirect();

        return $redirect->getTargetUrl();
    }

    /** @throws GoogleOAuthException */
    public function handleCallback(Request $request): User
    {
        $providerError = trim((string) $request->query('error', ''));

        if ($providerError !== '') {
            throw new GoogleOAuthException(
                $providerError === 'access_denied'
                    ? GoogleOAuthException::CANCELLED
                    : GoogleOAuthException::INVALID_CALLBACK,
            );
        }

        if (! $request->filled('code') || ! $request->filled('state')) {
            throw new GoogleOAuthException(GoogleOAuthException::INVALID_CALLBACK);
        }

        try {
            $googleUser = $this->socialite->driver(self::PROVIDER)->user();
            $user = $this->resolveCustomer($googleUser);
            $this->authenticate($user, $request);

            return $user;
        } catch (GoogleOAuthException $exception) {
            throw $exception;
        } catch (InvalidStateException) {
            throw new GoogleOAuthException(GoogleOAuthException::INVALID_CALLBACK);
        } catch (Throwable) {
            throw new GoogleOAuthException(GoogleOAuthException::AUTH_FAILED);
        }
    }

    public function successRedirectUrl(Request $request): string
    {
        $query = ['status' => 'success'];
        $intendedPath = $this->pullIntendedPath($request);

        if ($intendedPath !== null) {
            $query['redirect'] = $intendedPath;
        }

        return $this->frontendUrl(
            (string) config('services.google.frontend_callback_path', '/auth/google/callback'),
            $query,
            '/auth/google/callback',
        );
    }

    public function failureRedirectUrl(Request $request, string $safeCode): string
    {
        $this->pullIntendedPath($request);

        return $this->frontendUrl(
            (string) config('services.google.frontend_login_path', '/login'),
            ['oauth_error' => $safeCode],
            '/login',
        );
    }

    private function resolveCustomer(SocialiteUser $googleUser): User
    {
        $providerUserId = trim((string) $googleUser->getId());
        $providerEmail = Str::lower(trim((string) $googleUser->getEmail()));

        if ($providerUserId === '' || $providerEmail === '') {
            throw new GoogleOAuthException(GoogleOAuthException::INVALID_CALLBACK);
        }

        if (! $this->hasVerifiedEmail($googleUser)) {
            throw new GoogleOAuthException(GoogleOAuthException::UNVERIFIED_EMAIL);
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

    /** @throws GoogleOAuthException */
    private function ensureCustomer(User $user): User
    {
        if ($user->role !== UserRole::Customer) {
            throw new GoogleOAuthException(GoogleOAuthException::STAFF_ACCOUNT);
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

    private function rememberIntendedPath(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $intendedPath = $this->sanitizeRelativePath($request->query('redirect'));

        if ($intendedPath === null) {
            $request->session()->forget(self::INTENDED_PATH_SESSION_KEY);

            return;
        }

        $request->session()->put(self::INTENDED_PATH_SESSION_KEY, $intendedPath);
    }

    private function pullIntendedPath(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        return $this->sanitizeRelativePath(
            $request->session()->pull(self::INTENDED_PATH_SESSION_KEY),
        );
    }

    private function sanitizeRelativePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);

        if ($path === ''
            || ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            return null;
        }

        $parts = parse_url($path);

        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            return null;
        }

        return $path;
    }

    /** @param array<string, string> $query */
    private function frontendUrl(string $path, array $query, string $fallbackPath): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $safePath = $this->sanitizeRelativePath($path) ?? $fallbackPath;

        return $frontendUrl.$safePath.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
