<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth.login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth.register', function (Request $request): Limit {
            return Limit::perMinute(3)->by((string) $request->ip());
        });

        RateLimiter::for('password.recovery.request', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('password-recovery-request-email|'.$email),
                Limit::perMinute(10)->by('password-recovery-request-ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('password.recovery.verify', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('password-recovery-verify-email|'.$email),
                Limit::perMinute(30)->by('password-recovery-verify-ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('password.recovery.reset', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('password-recovery-reset-email|'.$email),
                Limit::perMinute(10)->by('password-recovery-reset-ip|'.$request->ip()),
            ];
        });
        RateLimiter::for('appointments.create', function (Request $request): Limit {
            return Limit::perMinute(5)->by('appointment-create|'.($request->user()?->id ?? $request->ip()));
        });
    }
}
