<?php

namespace App\Providers;

use App\Services\Media\CloudinaryClientContract;
use App\Services\Media\CloudinaryPublicMediaService;
use App\Services\Media\CloudinarySdkClient;
use App\Services\Media\LocalPrivateFileService;
use App\Services\Media\LocalPublicMediaService;
use App\Services\Media\LocalStagingMediaStorage;
use App\Services\Media\PrivateFileServiceContract;
use App\Services\Media\PublicMediaServiceContract;
use App\Services\Media\StagingMediaStorageContract;
use App\Support\MediaUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MediaUrl::class);
        $this->app->bind(CloudinaryClientContract::class, CloudinarySdkClient::class);
        $this->app->bind(PublicMediaServiceContract::class, function ($app): PublicMediaServiceContract {
            return match (config('media.public_driver', 'local')) {
                'local' => $app->make(LocalPublicMediaService::class),
                'cloudinary' => $app->make(CloudinaryPublicMediaService::class),
                default => throw new InvalidArgumentException('MEDIA_PUBLIC_DRIVER must be local or cloudinary.'),
            };
        });
        $this->app->bind(PrivateFileServiceContract::class, LocalPrivateFileService::class);
        $this->app->bind(StagingMediaStorageContract::class, LocalStagingMediaStorage::class);
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

        RateLimiter::for('auth.oauth', function (Request $request): Limit {
            return Limit::perMinute(10)->by('oauth|'.$request->ip());
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
