<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Repositories\Import\NumberedProductImageRepository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class NumberedProductImageRemapService
{
    private const NUMBERED_IMAGE_PATTERN = '/^(\d+)\.(jpg|jpeg|png|webp)$/i';

    private const OBSOLETE_HASHED_IMAGE_PATTERN = '/^[a-f0-9]{32,128}\.(jpg|jpeg|png|webp)$/i';

    public function __construct(
        private readonly NumberedProductImageRepository $repository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(
        string $source,
        ?string $externalId,
        bool $dryRun,
        bool $deleteObsolete,
    ): array {
        $source = trim($source);
        $externalId = $externalId === null ? null : trim($externalId);

        if ($source === '') {
            throw new RuntimeException('Source must not be empty.');
        }

        if ($externalId !== null && ! $this->safePathSegment($externalId)) {
            throw new RuntimeException('Product external ID is invalid.');
        }

        $result = [
            'scanned_products' => 0,
            'products_remapped' => 0,
            'products_skipped' => 0,
            'missing_folders' => 0,
            'numbered_files_found' => 0,
            'db_rows_inserted' => 0,
            'db_rows_updated' => 0,
            'db_rows_deleted' => 0,
            'obsolete_files_detected' => 0,
            'obsolete_files_deleted' => 0,
            'bytes_freed' => 0,
            'failures' => 0,
            'manifest_paths' => [],
        ];
        $disk = Storage::disk('public');

        foreach ($this->repository->importedProducts($source, $externalId) as $product) {
            $result['scanned_products']++;

            try {
                $this->processProduct($product, $disk, $dryRun, $deleteObsolete, $result);
            } catch (Throwable) {
                $result['failures']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function processProduct(
        Product $product,
        FilesystemAdapter $disk,
        bool $dryRun,
        bool $deleteObsolete,
        array &$result,
    ): void {
        $externalId = (string) $product->external_id;

        if (! $this->safePathSegment($externalId)) {
            $result['products_skipped']++;

            return;
        }

        $folder = "catalog/products/{$externalId}";

        if (! $disk->directoryExists($folder)) {
            $result['missing_folders']++;
            $result['products_skipped']++;

            return;
        }

        $numberedFiles = $this->numberedFiles($disk, $folder);

        if ($numberedFiles === []) {
            $result['products_skipped']++;

            return;
        }

        $result['numbered_files_found'] += count($numberedFiles);
        $targets = array_map(
            fn (string $path, int $index): array => [
                'image_url' => url($disk->url($path)),
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ],
            $numberedFiles,
            array_keys($numberedFiles),
        );
        $changes = $this->repository->reconcile($product, $targets, $dryRun);
        $result['db_rows_inserted'] += $changes['inserted'];
        $result['db_rows_updated'] += $changes['updated'];
        $result['db_rows_deleted'] += $changes['deleted'];
        $result['products_remapped']++;

        if ($dryRun) {
            return;
        }

        $obsolete = $this->obsoleteFiles($disk, $folder, $externalId);
        $result['obsolete_files_detected'] += count($obsolete);

        if (! $deleteObsolete || $obsolete === []) {
            return;
        }

        $manifest = array_map(fn (array $file): array => [
            'product_id' => $product->id,
            'source_external_id' => $externalId,
            'deleted_path' => $file['path'],
            'bytes' => $file['bytes'],
            'reason' => 'unreferenced_obsolete_hashed_product_image',
        ], $obsolete);
        $manifestPath = sprintf(
            'import-reports/catalog-image-cleanup-%s-%s-%s.json',
            $externalId,
            now()->format('Ymd-His-u'),
            bin2hex(random_bytes(4)),
        );
        Storage::disk('local')->put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $result['manifest_paths'][] = Storage::disk('local')->path($manifestPath);

        foreach ($obsolete as $file) {
            if ($disk->delete($file['path'])) {
                $result['obsolete_files_deleted']++;
                $result['bytes_freed'] += $file['bytes'];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function numberedFiles(FilesystemAdapter $disk, string $folder): array
    {
        $files = array_values(array_filter(
            $disk->files($folder),
            fn (string $path): bool => dirname(str_replace('\\', '/', $path)) === $folder
                && preg_match(self::NUMBERED_IMAGE_PATTERN, basename($path)) === 1,
        ));

        usort($files, function (string $left, string $right): int {
            preg_match(self::NUMBERED_IMAGE_PATTERN, basename($left), $leftMatch);
            preg_match(self::NUMBERED_IMAGE_PATTERN, basename($right), $rightMatch);

            return [(int) $leftMatch[1], strtolower(basename($left))]
                <=> [(int) $rightMatch[1], strtolower(basename($right))];
        });

        return $files;
    }

    /**
     * @return list<array{path: string, bytes: int}>
     */
    private function obsoleteFiles(
        FilesystemAdapter $disk,
        string $folder,
        string $externalId,
    ): array {
        $referenced = array_fill_keys(array_filter(array_map(
            $this->normalizeReferencedPath(...),
            $this->repository->referencedImageUrls($externalId),
        )), true);
        $obsolete = [];

        foreach ($disk->files($folder) as $path) {
            $normalizedPath = str_replace('\\', '/', $path);

            if (
                dirname($normalizedPath) !== $folder
                || preg_match(self::NUMBERED_IMAGE_PATTERN, basename($normalizedPath)) === 1
                || preg_match(self::OBSOLETE_HASHED_IMAGE_PATTERN, basename($normalizedPath)) !== 1
                || isset($referenced[$normalizedPath])
                || ! is_file($disk->path($normalizedPath))
            ) {
                continue;
            }

            $obsolete[] = [
                'path' => $normalizedPath,
                'bytes' => (int) $disk->size($normalizedPath),
            ];
        }

        return $obsolete;
    }

    private function normalizeReferencedPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $path = rawurldecode(str_replace('\\', '/', $path));

        if (str_starts_with($path, '/storage/')) {
            return ltrim(substr($path, strlen('/storage/')), '/');
        }

        return str_starts_with($path, 'catalog/products/') ? $path : null;
    }

    private function safePathSegment(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1
            && $value !== '.'
            && $value !== '..';
    }
}
