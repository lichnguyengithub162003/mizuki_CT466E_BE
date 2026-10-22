<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Auth;

function actingAsPortalUser(TestCase $test, User $user): TestCase
{
    Auth::forgetGuards();
    $test->actingAs($user, 'web');

    $session = Auth::guard('web')->getSession();
    if ($user->role === UserRole::Customer) {
        $session->forget('staff_auth_generation');
    } else {
        $session->put('staff_auth_generation', $user->refresh()->auth_generation);
    }

    return $test;
}
