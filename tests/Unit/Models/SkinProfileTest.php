<?php

use App\Models\SkinProfile;
use App\Models\User;

test('it casts concerns and belongs to a customer', function (): void {
    $profile = new SkinProfile([
        'concerns' => ['mụn', 'thâm'],
    ]);
    $user = new User;

    expect($profile->concerns)->toBe(['mụn', 'thâm'])
        ->and($profile->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($user->skinProfile()->getRelated())->toBeInstanceOf(SkinProfile::class);
});
