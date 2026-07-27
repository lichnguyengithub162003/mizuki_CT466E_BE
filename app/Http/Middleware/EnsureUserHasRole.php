<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException();
        }

        $allowedRoles = collect($roles)
            ->map(fn (string $role): ?UserRole => UserRole::tryFrom($role))
            ->filter()
            ->all();

        if ($allowedRoles === [] || ! in_array($user->role, $allowedRoles, true)) {
            throw new AuthorizationException('Bạn không có quyền truy cập chức năng này');
        }

        return $next($request);
    }
}
