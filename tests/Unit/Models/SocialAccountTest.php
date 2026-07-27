<?php

use App\Models\SocialAccount;
use App\Models\User;

test('it belongs to a user', function (): void {
    $socialAccount = new SocialAccount();

    expect($socialAccount->user()->getRelated())->toBeInstanceOf(User::class);
});

test('it permits provider identity fields to be assigned', function (): void {
    $socialAccount = new SocialAccount();

    expect($socialAccount->isFillable('provider'))->toBeTrue()
        ->and($socialAccount->isFillable('provider_user_id'))->toBeTrue()
        ->and($socialAccount->isFillable('provider_email'))->toBeTrue();
});
