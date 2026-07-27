<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Repositories\SocialAccountRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it creates and updates a social account for a user', function (): void {
    $repository = new SocialAccountRepository(new SocialAccount());
    $user = User::factory()->create();

    $socialAccount = $repository->createOrUpdateForUser(
        user: $user,
        provider: 'google',
        providerUserId: 'google-123',
        providerEmail: 'customer@example.com',
        avatarUrl: 'https://example.com/avatar-a.jpg',
    );

    $updatedSocialAccount = $repository->createOrUpdateForUser(
        user: $user,
        provider: 'google',
        providerUserId: 'google-123',
        providerEmail: 'updated@example.com',
        avatarUrl: 'https://example.com/avatar-b.jpg',
    );

    expect($updatedSocialAccount->is($socialAccount))->toBeTrue()
        ->and($updatedSocialAccount->provider_email)->toBe('updated@example.com')
        ->and($updatedSocialAccount->avatar_url)->toBe('https://example.com/avatar-b.jpg');
});

test('it finds a social account by provider and provider user id', function (): void {
    $repository = new SocialAccountRepository(new SocialAccount());
    $user = User::factory()->create();
    $socialAccount = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'provider_email' => 'customer@example.com',
        'avatar_url' => null,
    ]);

    $foundSocialAccount = $repository->findByProviderAndProviderUserId('google', 'google-123');

    expect($foundSocialAccount?->is($socialAccount))->toBeTrue()
        ->and($foundSocialAccount?->user?->is($user))->toBeTrue();
});

test('it finds a social account by user and provider', function (): void {
    $repository = new SocialAccountRepository(new SocialAccount());
    $user = User::factory()->create();
    $socialAccount = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'provider_email' => 'customer@example.com',
        'avatar_url' => null,
    ]);

    $foundSocialAccount = $repository->findByUserAndProvider($user, 'google');

    expect($foundSocialAccount?->is($socialAccount))->toBeTrue();
});
