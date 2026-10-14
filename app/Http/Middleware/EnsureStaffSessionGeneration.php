<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffSessionGeneration
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if ($user->role === UserRole::Customer) {
            return $next($request);
        }

        // Re-read the active account: an already-resolved guard user may be stale
        // when an account is trashed during the current worker's lifetime.
        $generation = User::query()->whereKey($user->id)->value('auth_generation');
        if ($generation === null) {
            throw new AuthenticationException;
        }

        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            return $next($request);
        }

        if (Auth::guard('web')->getSession()->get('staff_auth_generation') !== (int) $generation) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
