<?php

use App\Http\Resources\Auth\OAuthRedirectResource;
use Illuminate\Http\Request;

test('it transforms an oauth redirect url for API responses', function (): void {
    $payload = (new OAuthRedirectResource([
        'redirect_url' => 'https://accounts.google.com/o/oauth2/auth',
    ]))->toArray(new Request());

    expect($payload)->toBe([
        'redirect_url' => 'https://accounts.google.com/o/oauth2/auth',
    ]);
});
