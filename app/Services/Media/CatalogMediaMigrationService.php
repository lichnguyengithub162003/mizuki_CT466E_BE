<?php

namespace App\Services\Media;

use App\Models\ProductImage;
use App\Repositories\CatalogMediaMigrationRepository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use InvalidArgumentException;
use Throwable;

final class CatalogMediaMigrationService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'png', 'webp'];

    public function __construct(
        private readonly CatalogMediaMigrationRepository $repository,
        private readonly PublicMediaServiceContract $publicMedia,
        private readonly MediaKeyGenerator $keys,
        private readonly FilesystemManager $filesystems,
    ) {}

    /**
     * @param  list<int>  $productIds
     * @return array<string, mixed>
     */
    public function execute(
        int $limit = 50,
        ?int $fromId = null,
        ?int $toId = null,
        array $productIds = [],
        bool $dryRun = false,
    ): array {
        $this->assertConfiguration($limit, $fromId, $toId, $productIds);

        $selectedProductIds = $this->repository->productIds($limit, $fromId, $toId, $productIds);
        $images = $this->repository->images($selectedProductIds);
        $result = $this->emptyResult($dryRun, $selectedProductIds->count(), $images->count());
        $seenSources = [];

        foreach ($images as $image) {
            $source = $this->localSource((string) $image->image_url);
            $status = $source['status'];

            if ($status !== 'eligible') {
                $this->recordSkip($result, $image, $status);

                continue;
            }

            $path = $source['path'];
            $extension = $source['extension'];
            $sourceIdentity = strtolower(str_replace('\\', '/', $path));
            if (isset($seenSources[$sourceIdentity])) {
                $result['duplicate_sources']++;
            }
            $seenSources[$sourceIdentity] = true;

            $result['eligible']++;
            $result['planned_uploads']++;
            $result['planned_db_updates']++;
            $result['planned_source_bytes'] += filesize($path) ?: 0;

            if ($dryRun) {
                $this->record($result, $image, 'planned');

                continue;
            }

            $this->migrateImage($image, $path, $extension, $result);
        }

        return $result;
    }

    /** @param array<string, mixed> $result */
    private function migrateImage(ProductImage $image, string $path, string $extension, array &$result): void
    {
        $key = $image->product_variant_id === null
            ? $this->keys->productGallery((int) $image->product_id, $extension)
            : $this->keys->productVariant((int) $image->product_id, (int) $image->product_variant_id, $extension);
        $sourceReference = (string) $image->image_url;
        $bytes = filesize($path) ?: 0;

        try {
            $object = $this->publicMedia->put(
                key: $key,
                contents: $path,
                mimeType: mime_content_type($path) ?: null,
                originalName: basename($path),
            );
            $result['successful_uploads']++;
            $result['total_source_bytes_uploaded'] += $bytes;
        } catch (Throwable $exception) {
            $result['failed_uploads']++;
            $result['failures']++;
            $this->record($result, $image, 'upload_failed', $exception->getMessage());

            return;
        }

        try {
            if (! str_starts_with($object->key, 'cloudinary:mizuki/')) {
                throw new InvalidArgumentException('Public media upload did not return an app-owned Cloudinary reference.');
            }

            if (! $this->repository->replaceReference($image, $sourceReference, $object->key)) {
                throw new InvalidArgumentException('Product image changed before its migrated reference could be saved.');
            }

            $result['db_updates']++;
            $this->record($result, $image, 'migrated', reference: $object->key);
        } catch (Throwable $exception) {
            $result['db_failures']++;
            $result['failures']++;
            $compensated = $this->compensate($object->key);
            $result[$compensated ? 'compensated_failures' : 'compensation_failures']++;
            $this->record($result, $image, 'db_failed', $exception->getMessage(), $object->key);
        }
    }

    private function compensate(string $reference): bool
    {
        try {
            return $this->publicMedia->delete($reference);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{status: string, path?: string, extension?: string}
     */
    private function localSource(string $reference): array
    {
        $reference = trim(str_replace('\\', '/', $reference));
        if ($reference === '') {
            return ['status' => 'missing_source'];
        }
        if (str_starts_with($reference, 'cloudinary:')) {
            return ['status' => 'already_cloudinary'];
        }
        if (str_starts_with($reference, 'private/') || str_starts_with($reference, 'staging/')) {
            return ['status' => 'non_public'];
        }

        $relative = $this->relativePublicPath($reference);
        if ($relative === null) {
            return ['status' => 'external'];
        }
        if ($relative === '' || str_contains($relative, '../') || str_starts_with($relative, '..')) {
            return ['status' => 'unsupported'];
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $extension = in_array($extension, ['jpeg', 'jpe'], true) ? 'jpg' : $extension;
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ['status' => 'unsupported'];
        }

        $disk = $this->publicDisk();
        if (! $disk->exists($relative)) {
            return ['status' => 'missing_source'];
        }

        $root = realpath($disk->path(''));
        $path = realpath($disk->path($relative));
        if ($root === false || $path === false || ! str_starts_with(strtolower($path), strtolower(rtrim($root, '\\/')).DIRECTORY_SEPARATOR)) {
            return ['status' => 'unsupported'];
        }
        if (! is_file($path) || ! is_readable($path)) {
            return ['status' => 'missing_source'];
        }

        return ['status' => 'eligible', 'path' => $path, 'extension' => $extension];
    }

    private function relativePublicPath(string $reference): ?string
    {
        if (preg_match('~^https?://~i', $reference) === 1) {
            $host = strtolower((string) parse_url($reference, PHP_URL_HOST));
            $localHosts = array_filter([
                strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
                strtolower((string) parse_url((string) config('filesystems.disks.public.url'), PHP_URL_HOST)),
                'localhost',
                '127.0.0.1',
            ]);
            $path = (string) parse_url($reference, PHP_URL_PATH);
            if (! in_array($host, $localHosts, true) || ! str_starts_with($path, '/storage/')) {
                return null;
            }

            return ltrim(substr($path, strlen('/storage/')), '/');
        }

        $publicRoot = str_replace('\\', '/', storage_path('app/public'));
        if (preg_match('~^[A-Za-z]:/~', $reference) === 1) {
            if (! str_starts_with(strtolower($reference), strtolower($publicRoot).'/')) {
                return null;
            }

            return ltrim(substr($reference, strlen($publicRoot)), '/');
        }

        $reference = preg_replace('~^/?storage/~i', '', $reference) ?? $reference;

        return ltrim($reference, '/');
    }

    /** @param list<int> $productIds */
    private function assertConfiguration(int $limit, ?int $fromId, ?int $toId, array $productIds): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Batch limit must be between 1 and 100 products.');
        }
        if ($fromId !== null && $fromId < 1 || $toId !== null && $toId < 1) {
            throw new InvalidArgumentException('Product ID range values must be positive integers.');
        }
        if ($fromId !== null && $toId !== null && $fromId > $toId) {
            throw new InvalidArgumentException('Product ID range start cannot exceed its end.');
        }
        if (count($productIds) > 100 || collect($productIds)->contains(fn (int $id): bool => $id < 1)) {
            throw new InvalidArgumentException('Product ID list must contain at most 100 positive integers.');
        }
        if (config('media.public_driver') !== 'cloudinary') {
            throw new InvalidArgumentException('MEDIA_PUBLIC_DRIVER must be cloudinary for catalog migration.');
        }
        if (! is_string(config('media.cloudinary_url')) || trim((string) config('media.cloudinary_url')) === '') {
            throw new InvalidArgumentException('CLOUDINARY_URL must be configured for catalog migration.');
        }
    }

    private function publicDisk(): FilesystemAdapter
    {
        return $this->filesystems->disk((string) config('media.public_disk', 'public'));
    }

    /** @return array<string, mixed> */
    private function emptyResult(bool $dryRun, int $products, int $images): array
    {
        return [
            'dry_run' => $dryRun,
            'products_inspected' => $products,
            'image_records_inspected' => $images,
            'eligible' => 0,
            'already_cloudinary' => 0,
            'missing_source' => 0,
            'non_public' => 0,
            'external' => 0,
            'unsupported' => 0,
            'skipped' => 0,
            'duplicate_sources' => 0,
            'planned_uploads' => 0,
            'planned_db_updates' => 0,
            'planned_source_bytes' => 0,
            'successful_uploads' => 0,
            'failed_uploads' => 0,
            'db_updates' => 0,
            'db_failures' => 0,
            'compensated_failures' => 0,
            'compensation_failures' => 0,
            'failures' => 0,
            'total_source_bytes_uploaded' => 0,
            'items' => [],
        ];
    }

    /** @param array<string, mixed> $result */
    private function recordSkip(array &$result, ProductImage $image, string $status): void
    {
        $result[$status]++;
        $result['skipped']++;
        $this->record($result, $image, $status);
    }

    /** @param array<string, mixed> $result */
    private function record(
        array &$result,
        ProductImage $image,
        string $status,
        ?string $message = null,
        ?string $reference = null,
    ): void {
        $result['items'][] = array_filter([
            'image_id' => (int) $image->id,
            'product_id' => (int) $image->product_id,
            'variant_id' => $image->product_variant_id === null ? null : (int) $image->product_variant_id,
            'status' => $status,
            'message' => $message,
            'reference' => $reference,
        ], fn (mixed $value): bool => $value !== null);
    }
}
