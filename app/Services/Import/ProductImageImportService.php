<?php

namespace App\Services\Import;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ProductImageImportService
{
    public const FALLBACK_URL = '/images/product-placeholder.svg';

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $createdPaths
     * @return list<array{image_url: string, alt_text: string, sort_order: int, is_primary: bool}>
     */
    public function importForRecord(
        array $record,
        string $sourceDirectory,
        bool $skipImages,
        array &$createdPaths,
    ): array {
        if ($skipImages) {
            return $this->fallback($record);
        }

        $sourceRoot = realpath($sourceDirectory);
        $localImages = $record['metadata']['local_images'] ?? [];

        if ($sourceRoot === false || ! is_array($localImages)) {
            return $this->fallback($record);
        }

        $disk = Storage::disk('public');
        $images = [];
        $seenHashes = [];

        foreach ($localImages as $relativePath) {
            if (! is_string($relativePath) || $relativePath === '') {
                continue;
            }

            $sourcePath = realpath($sourceRoot.DIRECTORY_SEPARATOR.str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $relativePath,
            ));

            if ($sourcePath === false || ! $this->isInside($sourcePath, $sourceRoot) || ! is_file($sourcePath)) {
                continue;
            }

            $extension = $this->extensionFor($sourcePath);

            if ($extension === null) {
                continue;
            }

            $hash = hash_file('sha256', $sourcePath);

            if ($hash === false || isset($seenHashes[$hash])) {
                continue;
            }

            $seenHashes[$hash] = true;
            $destination = sprintf(
                'catalog/products/%s/%s.%s',
                $record['source_id'],
                $hash,
                $extension,
            );

            if (! $disk->exists($destination)) {
                $contents = file_get_contents($sourcePath);

                if ($contents === false || ! $disk->put($destination, $contents)) {
                    throw new RuntimeException('A local product image could not be copied to Mizuki storage.');
                }

                $createdPaths[] = $destination;
            }

            $images[] = [
                'image_url' => $disk->url($destination),
                'alt_text' => $record['product']['name'],
                'sort_order' => count($images),
                'is_primary' => $images === [],
            ];
        }

        return $images === [] ? $this->fallback($record) : $images;
    }

    /** @param  list<string>  $paths */
    public function rollbackCreatedFiles(array $paths): void
    {
        if ($paths !== []) {
            $this->disk()->delete(array_values(array_unique($paths)));
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk('public');
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function extensionFor(string $path): ?string
    {
        return match (mime_content_type($path)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{image_url: string, alt_text: string, sort_order: int, is_primary: bool}>
     */
    private function fallback(array $record): array
    {
        return [[
            'image_url' => self::FALLBACK_URL,
            'alt_text' => $record['product']['name'],
            'sort_order' => 0,
            'is_primary' => true,
        ]];
    }
}
