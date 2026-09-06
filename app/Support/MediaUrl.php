<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MediaUrl
{
    /** @var array<string, string|null> */
    private array $resolved = [];

    /** @var array<string, string>|null */
    private ?array $brandLogoPaths = null;

    public function resolve(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! array_key_exists($value, $this->resolved)) {
            $this->resolved[$value] = $this->resolveUncached($value);
        }

        return $this->resolved[$value];
    }

    public function brandLogo(?string $value, string $slug, string $name): ?string
    {
        $manifest = $this->brandLogoPaths();

        foreach ([$slug, $name] as $candidate) {
            $path = $manifest[Str::slug($candidate)] ?? null;

            if ($path !== null) {
                return $this->resolve($path);
            }
        }

        return $this->resolve($value);
    }

    private function resolveUncached(string $value): ?string
    {
        $normalized = str_replace('\\', '/', $value);

        if (preg_match('~^(?:data|file):~i', $normalized) === 1) {
            return null;
        }

        if (preg_match('~^https?://~i', $normalized) === 1) {
            $path = (string) parse_url($normalized, PHP_URL_PATH);
            $host = Str::lower((string) parse_url($normalized, PHP_URL_HOST));

            if ($this->isLocalHost($host) && str_starts_with($path, '/storage/')) {
                return $this->publicDiskUrl(substr($path, strlen('/storage/')));
            }

            return $normalized;
        }

        if (str_starts_with($normalized, '/images/') && is_file(public_path(ltrim($normalized, '/')))) {
            return $normalized;
        }

        $publicRoot = str_replace('\\', '/', storage_path('app/public'));

        if (preg_match('~^[A-Za-z]:/~', $normalized) === 1) {
            if (! str_starts_with(Str::lower($normalized), Str::lower($publicRoot).'/')) {
                return null;
            }

            $normalized = substr($normalized, strlen($publicRoot) + 1);
        } elseif (($position = stripos($normalized, 'storage/app/public/')) !== false) {
            $normalized = substr($normalized, $position + strlen('storage/app/public/'));
        }

        $normalized = preg_replace('~^/?(?:public/)?storage/~i', '', $normalized) ?? $normalized;

        return $this->publicDiskUrl(ltrim($normalized, '/'));
    }

    private function publicDiskUrl(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '../') || str_starts_with($path, '..')) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->url($path);
    }

    private function isLocalHost(string $host): bool
    {
        $configuredHosts = collect([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('filesystems.disks.public.url'), PHP_URL_HOST),
            'localhost',
            '127.0.0.1',
        ])->filter()->map(fn (mixed $value): string => Str::lower((string) $value));

        return $configuredHosts->contains($host);
    }

    /** @return array<string, string> */
    private function brandLogoPaths(): array
    {
        if ($this->brandLogoPaths !== null) {
            return $this->brandLogoPaths;
        }

        /** @var Filesystem $disk */
        $disk = Storage::disk('public');

        return $this->brandLogoPaths = collect($disk->files('catalog/brands'))
            ->filter(fn (string $path): bool => in_array(
                Str::lower(pathinfo($path, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp', 'svg'],
                true,
            ))
            ->sort()
            ->mapWithKeys(fn (string $path): array => [
                Str::slug(pathinfo($path, PATHINFO_FILENAME)) => $path,
            ])
            ->all();
    }
}
