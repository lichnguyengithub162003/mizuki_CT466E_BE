<?php

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set([
        'app.frontend_url' => 'http://localhost:5173',
        'services.google.frontend_callback_path' => '/auth/google/callback',
        'services.google.frontend_login_path' => '/login',
    ]);
});

final class StatefulGoogleFeatureProvider extends AbstractProvider
{
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://accounts.google.test/oauth', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://accounts.google.test/token';
    }

    protected function getUserByToken($token): array
    {
        throw new LogicException('Network token exchange must not run in this state test.');
    }

    protected function mapUserToObject(array $user): SocialiteUser
    {
        throw new LogicException('Network user mapping must not run in this state test.');
    }

    public function user(): SocialiteUser
    {
        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        return verifiedGoogleFeatureUser();
    }
}

function bindStatefulGoogleProvider(int $expectedCalls = 2): void
{
    /** @var SocialiteFactory&MockInterface $socialite */
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')
        ->times($expectedCalls)
        ->with('google')
        ->andReturnUsing(fn (): StatefulGoogleFeatureProvider => new StatefulGoogleFeatureProvider(
            request(),
            'test-client-id',
            'test-client-secret',
            'http://localhost:8000/api/v1/auth/google/callback',
        ));

    app()->instance(SocialiteFactory::class, $socialite);
}
function sessionIdFromResponse($response): string
{
    $sessionCookieName = (string) config('session.cookie');
    $sessionCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === $sessionCookieName);

    expect($sessionCookie)->not->toBeNull();
    $prefixedSessionId = app('encrypter')->decrypt($sessionCookie->getValue(), false);

    return CookieValuePrefix::remove($prefixedSessionId);
}

function persistBrowserSessionCookie($test, $response): string
{
    $sessionId = sessionIdFromResponse($response);
    $test->withCookie((string) config('session.cookie'), $sessionId)->withCredentials();

    return hash('sha256', $sessionId);
}
function verifiedGoogleFeatureUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake(array_merge([
        'id' => 'google-123',
        'name' => 'Google Customer',
        'email' => 'customer@example.com',
        'avatar' => 'https://example.com/avatar.jpg',
        'email_verified' => true,
    ], $attributes));
}

function bindGoogleCallbackProvider(SocialiteUser|Throwable $result): void
{
    /** @var Provider&MockInterface $provider */
    $provider = Mockery::mock(Provider::class);

    if ($result instanceof Throwable) {
        $provider->shouldReceive('user')->once()->andThrow($result);
    } else {
        $provider->shouldReceive('user')->once()->andReturn($result);
    }

    /** @var SocialiteFactory&MockInterface $socialite */
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $socialite);
}

function bindGoogleRedirectProvider(): void
{
    /** @var Provider&MockInterface $provider */
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')->once()
        ->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth'));

    /** @var SocialiteFactory&MockInterface $socialite */
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $socialite);
}

function bindCompleteGoogleFlow(SocialiteUser $googleUser): void
{
    /** @var Provider&MockInterface $provider */
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')->once()
        ->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth'));
    $provider->shouldReceive('user')->once()->andReturn($googleUser);

    /** @var SocialiteFactory&MockInterface $socialite */
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->twice()->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $socialite);
}

test('redirect endpoint still returns the google oauth URL', function (): void {
    bindGoogleRedirectProvider();

    $this->getJson('/api/v1/auth/google/redirect')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.redirect_url', 'https://accounts.google.com/o/oauth2/auth')
        ->assertJsonPath('message', 'Tạo liên kết đăng nhập Google thành công!');
});

test('verified google callback creates customer authenticates session and redirects to frontend', function (): void {
    bindGoogleCallbackProvider(verifiedGoogleFeatureUser());

    $response = $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/auth/google/callback?status=success');

    $user = User::query()->where('email', 'customer@example.com')->firstOrFail();
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
    ]);
    $this->assertAuthenticatedAs($user);
    $response->assertCookie(config('session.cookie'));

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);
});

test('existing linked customer logs in and redirects to frontend', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'role' => UserRole::Customer,
    ]);
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'provider_email' => 'customer@example.com',
        'avatar_url' => null,
    ]);
    bindGoogleCallbackProvider(verifiedGoogleFeatureUser());

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/auth/google/callback?status=success');

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('social_accounts', 1);
});

test('existing unlinked customer is linked and logged in', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'role' => UserRole::Customer,
    ]);
    bindGoogleCallbackProvider(verifiedGoogleFeatureUser());

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/auth/google/callback?status=success');

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
    ]);
});

test('staff google account redirects with stable safe error code', function (): void {
    User::factory()->create([
        'email' => 'staff@example.com',
        'role' => UserRole::Cashier,
    ]);
    bindGoogleCallbackProvider(verifiedGoogleFeatureUser(['email' => 'staff@example.com']));

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_staff_account');

    $this->assertGuest();
    $this->assertDatabaseCount('social_accounts', 0);
});

test('unverified google email redirects with stable safe error code', function (): void {
    bindGoogleCallbackProvider(verifiedGoogleFeatureUser(['email_verified' => false]));

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_unverified_email');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

test('cancelled google authorization redirects with safe cancellation code', function (): void {
    $response = $this->get('/api/v1/auth/google/callback?error=access_denied&error_description=provider-secret-token')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_cancelled');

    expect($response->headers->get('Location'))->not->toContain('provider-secret-token');
});

test('missing callback parameters redirect with invalid callback code', function (): void {
    $this->get('/api/v1/auth/google/callback?code=missing-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_invalid_callback');
});

test('socialite invalid state redirects with invalid callback code', function (): void {
    bindGoogleCallbackProvider(new InvalidStateException);

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=invalid-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_invalid_callback');
});

test('provider failures never expose exception details or tokens in redirect URL', function (): void {
    bindGoogleCallbackProvider(new RuntimeException('provider access_token=secret-value'));

    $response = $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_auth_failed');
    $location = (string) $response->headers->get('Location');

    expect($location)->not->toContain('secret-value')
        ->not->toContain('access_token')
        ->not->toContain('RuntimeException');
});

test('valid relative intended destination is preserved through the oauth session', function (): void {
    bindCompleteGoogleFlow(verifiedGoogleFeatureUser());

    $this->getJson('/api/v1/auth/google/redirect?redirect=%2Forders%2F42%3Ftab%3Dpayment')->assertOk();

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/auth/google/callback?status=success&redirect=%2Forders%2F42%3Ftab%3Dpayment');
});

test('unsafe intended destinations are rejected and cannot create an open redirect', function (string $destination): void {
    bindCompleteGoogleFlow(verifiedGoogleFeatureUser());

    $this->getJson('/api/v1/auth/google/redirect?redirect='.urlencode($destination))->assertOk();

    $response = $this->get('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertRedirect('http://localhost:5173/auth/google/callback?status=success');

    expect($response->headers->get('Location'))->not->toContain('evil.example');
})->with([
    'absolute external URL' => 'https://evil.example/steal',
    'protocol relative URL' => '//evil.example/steal',
    'backslash authority confusion' => '/\\evil.example/steal',
]);
test('real stateful socialite sequence preserves state in the same browser session', function (): void {
    bindStatefulGoogleProvider();

    $redirectResponse = $this->withHeader('Origin', 'http://localhost:5173')
        ->getJson('/api/v1/auth/google/redirect')
        ->assertOk();
    $query = [];
    parse_str((string) parse_url($redirectResponse->json('data.redirect_url'), PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('state')
        ->and($query['state'])->toBeString()->not->toBeEmpty();

    $this->get('/api/v1/auth/google/callback?code=valid-code&state='.urlencode($query['state']))
        ->assertRedirect('http://localhost:5173/auth/google/callback?status=success');

    $user = User::query()->where('email', 'customer@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

test('real stateful socialite sequence rejects a mismatched callback state', function (): void {
    bindStatefulGoogleProvider();

    $this->withHeader('Origin', 'http://localhost:5173')
        ->getJson('/api/v1/auth/google/redirect')
        ->assertOk();

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=mismatched-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_invalid_callback');

    $this->assertGuest();
});

test('real stateful socialite callback rejects a missing browser session', function (): void {
    bindStatefulGoogleProvider(1);

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=orphaned-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_invalid_callback');

    $this->assertGuest();
});
test('same browser session can complete three google logins across customer logouts', function (): void {
    bindStatefulGoogleProvider(6);
    $sessionCookieName = (string) config('session.cookie');
    $seenStates = [];
    $anonymousSessionFingerprint = null;
    $this->withCredentials();

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $redirectResponse = $this->getJson(
            '/api/v1/auth/google/redirect',
            ['Origin' => 'http://localhost:5173'],
        )->assertOk();
        $redirectSessionFingerprint = persistBrowserSessionCookie($this, $redirectResponse);

        if ($anonymousSessionFingerprint !== null) {
            expect($redirectSessionFingerprint)->toBe($anonymousSessionFingerprint);
        }

        $query = [];
        parse_str((string) parse_url($redirectResponse->json('data.redirect_url'), PHP_URL_QUERY), $query);

        expect($query)->toHaveKey('state')
            ->and($query['state'])->toBeString()->not->toBeEmpty()
            ->and($seenStates)->not->toContain($query['state']);
        $seenStates[] = $query['state'];

        $sessionCookies = array_values(array_filter(
            $redirectResponse->headers->getCookies(),
            fn ($cookie): bool => $cookie->getName() === $sessionCookieName,
        ));
        expect($sessionCookies)->toHaveCount(1);

        $callbackResponse = $this->get(
            '/api/v1/auth/google/callback?code=valid-code&state='.urlencode($query['state']),
        )->assertRedirect('http://localhost:5173/auth/google/callback?status=success');
        $authenticatedSessionFingerprint = persistBrowserSessionCookie($this, $callbackResponse);
        expect($authenticatedSessionFingerprint)->not->toBe($redirectSessionFingerprint);

        $meResponse = $this->getJson('/api/v1/auth/me', ['Origin' => 'http://localhost:5173'])
            ->assertOk()
            ->assertJsonPath('data.email', 'customer@example.com');
        expect(persistBrowserSessionCookie($this, $meResponse))->toBe($authenticatedSessionFingerprint);

        if ($attempt < 3) {
            $logoutResponse = $this->postJson(
                '/api/v1/auth/logout',
                [],
                ['Origin' => 'http://localhost:5173'],
            )->assertOk();
            $logoutSessionCookies = array_values(array_filter(
                $logoutResponse->headers->getCookies(),
                fn ($cookie): bool => $cookie->getName() === $sessionCookieName,
            ));

            expect($logoutSessionCookies)->toHaveCount(1);
            $anonymousSessionFingerprint = persistBrowserSessionCookie($this, $logoutResponse);
            expect($anonymousSessionFingerprint)->not->toBe($authenticatedSessionFingerprint);

            $guestMeResponse = $this->getJson('/api/v1/auth/me', ['Origin' => 'http://localhost:5173'])
                ->assertUnauthorized();
            expect(persistBrowserSessionCookie($this, $guestMeResponse))->toBe($anonymousSessionFingerprint);
        }
    }

    expect($seenStates)->toHaveCount(3);
    $this->assertAuthenticated();
});
test('second google callback after logout still rejects a mismatched state', function (): void {
    bindStatefulGoogleProvider(4);
    $this->withCredentials();

    $firstRedirect = $this->getJson(
        '/api/v1/auth/google/redirect',
        ['Origin' => 'http://localhost:5173'],
    )->assertOk();
    persistBrowserSessionCookie($this, $firstRedirect);
    $firstQuery = [];
    parse_str((string) parse_url($firstRedirect->json('data.redirect_url'), PHP_URL_QUERY), $firstQuery);

    $firstCallback = $this->get(
        '/api/v1/auth/google/callback?code=valid-code&state='.urlencode($firstQuery['state']),
    )->assertRedirect('http://localhost:5173/auth/google/callback?status=success');
    persistBrowserSessionCookie($this, $firstCallback);

    $logout = $this->postJson(
        '/api/v1/auth/logout',
        [],
        ['Origin' => 'http://localhost:5173'],
    )->assertOk();
    persistBrowserSessionCookie($this, $logout);

    $secondRedirect = $this->getJson(
        '/api/v1/auth/google/redirect',
        ['Origin' => 'http://localhost:5173'],
    )->assertOk();
    persistBrowserSessionCookie($this, $secondRedirect);

    $this->get('/api/v1/auth/google/callback?code=valid-code&state=mismatched-second-state')
        ->assertRedirect('http://localhost:5173/login?oauth_error=google_invalid_callback');

    $this->assertGuest();
});
