<?php

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use App\Repositories\SocialAccountRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function googleAuthServiceWithUser(SocialiteUser $googleUser): GoogleAuthService
{
    /** @var Provider&MockInterface $provider */
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($googleUser);

    /** @var SocialiteFactory&MockInterface $socialite */
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('google')->andReturn($provider);

    return new GoogleAuthService(
        socialite: $socialite,
        users: new UserRepository(new User()),
        socialAccounts: new SocialAccountRepository(new SocialAccount()),
    );
}

function verifiedGoogleUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake(array_merge([
        'id' => 'google-123',
        'name' => 'Google Customer',
        'email' => 'customer@example.com',
        'avatar' => 'https://example.com/avatar.jpg',
        'email_verified' => true,
    ], $attributes));
}

function googleCallbackRequest(): Request
{
    $request = Request::create('/api/v1/auth/google/callback', 'GET');
    $request->setLaravelSession(app('session.store'));

    return $request;
}

test('it creates and authenticates a new customer from a verified google account', function (): void {
    $service = googleAuthServiceWithUser(verifiedGoogleUser());

    $user = $service->handleCallback(googleCallbackRequest());

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('customer@example.com')
        ->and($user->role)->toBe(UserRole::Customer)
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'provider_email' => 'customer@example.com',
    ]);
    $this->assertAuthenticatedAs($user);
});

test('it links a verified google account to an existing customer by email', function (): void {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'role' => UserRole::Customer,
    ]);
    $service = googleAuthServiceWithUser(verifiedGoogleUser());

    $authenticatedUser = $service->handleCallback(googleCallbackRequest());

    expect($authenticatedUser->is($user))->toBeTrue();
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
    ]);
});

test('it authenticates a customer by an existing linked google account', function (): void {
    $user = User::factory()->create([
        'email' => 'old-email@example.com',
        'role' => UserRole::Customer,
    ]);
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'provider_email' => 'old-email@example.com',
        'avatar_url' => null,
    ]);
    $service = googleAuthServiceWithUser(verifiedGoogleUser([
        'email' => 'new-email@example.com',
    ]));

    $authenticatedUser = $service->handleCallback(googleCallbackRequest());

    expect($authenticatedUser->is($user))->toBeTrue();
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'provider_email' => 'new-email@example.com',
    ]);
});

test('it rejects google login for internal staff email accounts', function (): void {
    User::factory()->create([
        'email' => 'customer@example.com',
        'role' => UserRole::Cashier,
    ]);
    $service = googleAuthServiceWithUser(verifiedGoogleUser());

    $service->handleCallback(googleCallbackRequest());
})->throws(AuthenticationException::class, 'Tài khoản không có quyền đăng nhập khu vực khách hàng!');

test('it rejects google accounts without a verified email', function (): void {
    $service = googleAuthServiceWithUser(verifiedGoogleUser([
        'email_verified' => false,
    ]));

    $service->handleCallback(googleCallbackRequest());
})->throws(AuthenticationException::class, 'Không thể xác thực email Google');
