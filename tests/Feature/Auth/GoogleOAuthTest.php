<?php

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function mockGoogleProviderForFeature(SocialiteUser $googleUser): void
{
    /** @var Provider&MockInterface $provider */
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($googleUser);

    /** @var SocialiteFactory&MockInterface $socialite */
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $socialite);
}

test('it returns the google oauth redirect url', function (): void {
    /** @var Provider&MockInterface $provider */
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')->once()->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth'));

    /** @var SocialiteFactory&MockInterface $socialite */
    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('google')->andReturn($provider);
    app()->instance(SocialiteFactory::class, $socialite);

    $this->getJson('/api/v1/auth/google/redirect')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.redirect_url', 'https://accounts.google.com/o/oauth2/auth')
        ->assertJsonPath('message', 'Tạo liên kết đăng nhập Google thành công!');
});

test('it creates and logs in a customer from a verified google callback', function (): void {
    mockGoogleProviderForFeature(SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Google Customer',
        'email' => 'customer@example.com',
        'avatar' => 'https://example.com/avatar.jpg',
        'email_verified' => true,
    ]));

    $response = $this->getJson('/api/v1/auth/google/callback?code=valid-code&state=valid-state');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Đăng nhập Google thành công!')
        ->assertJsonPath('data.email', 'customer@example.com')
        ->assertJsonPath('data.role', UserRole::Customer->value);

    $user = User::query()->where('email', 'customer@example.com')->firstOrFail();
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
    ]);
    $this->assertAuthenticatedAs($user);
});

test('it logs in an existing linked customer from google callback', function (): void {
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
    mockGoogleProviderForFeature(SocialiteUser::fake([
        'id' => 'google-123',
        'email' => 'customer@example.com',
        'email_verified' => true,
    ]));

    $this->getJson('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'customer@example.com');

    $this->assertAuthenticatedAs($user);
});

test('it rejects google callback when the email belongs to a staff account', function (): void {
    User::factory()->create([
        'email' => 'staff@example.com',
        'role' => UserRole::Cashier,
    ]);
    mockGoogleProviderForFeature(SocialiteUser::fake([
        'id' => 'google-123',
        'email' => 'staff@example.com',
        'email_verified' => true,
    ]));

    $this->getJson('/api/v1/auth/google/callback?code=valid-code&state=valid-state')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Tài khoản không có quyền đăng nhập khu vực khách hàng!');
});
