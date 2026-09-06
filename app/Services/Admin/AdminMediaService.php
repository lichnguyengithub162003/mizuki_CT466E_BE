<?php

namespace App\Services\Admin;

use App\Enums\MediaUploadStatus;
use App\Models\MediaUpload;
use App\Models\User;
use App\Repositories\MediaUploadRepository;
use App\Services\Media\MediaKeyGenerator;
use App\Services\Media\PublicMediaReference;
use App\Services\Media\PublicMediaServiceContract;
use App\Services\Media\StagingMediaStorageContract;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminMediaService
{
    public function __construct(
        private readonly MediaUploadRepository $uploads,
        private readonly StagingMediaStorageContract $staging,
        private readonly PublicMediaServiceContract $publicMedia,
        private readonly MediaKeyGenerator $keys,
        private readonly PublicMediaReference $references,
    ) {}

    /** @return array<string, mixed> */
    public function stageImage(User $actor, UploadedFile $image): array
    {
        [$extension, $mimeType] = $this->classifyImage($image);
        $token = Str::uuid()->toString();
        $key = $this->keys->staging($actor->id, $token, $extension);
        [$width, $height] = $this->dimensions($image);
        $expiresAt = now()->addHours(max(1, (int) config('media.upload_staging_ttl_hours', 24)));
        $previewTtl = $this->previewUrlTtlMinutes();
        $previewExpiresAt = now()->addMinutes($previewTtl);

        $this->staging->put($key, $image, $mimeType, $image->getClientOriginalName());

        try {
            $upload = $this->uploads->createUpload([
                'upload_token' => $token,
                'uploaded_by_user_id' => $actor->id,
                'staging_key' => $key,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'bytes' => (int) $image->getSize(),
                'width' => $width,
                'height' => $height,
                'original_name' => $image->getClientOriginalName(),
                'status' => MediaUploadStatus::Staged,
                'expires_at' => $expiresAt,
            ]);
        } catch (Throwable $exception) {
            $this->safeDelete($key);

            throw $exception;
        }

        return [
            'upload_token' => $upload->upload_token,
            'preview_url' => URL::temporarySignedRoute(
                'api.v1.admin.media.uploads.preview',
                $previewExpiresAt,
                ['uploadToken' => $upload->upload_token],
            ),
            'preview_expires_at' => $previewExpiresAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'mime_type' => $mimeType,
            'bytes' => $upload->bytes,
            'width' => $width,
            'height' => $height,
        ];
    }

    public function deleteStaging(User $actor, string $token): ?MediaUpload
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        return DB::transaction(function () use ($actor, $token): ?MediaUpload {
            $upload = $this->uploads->lockOwned($token, $actor->id);

            if ($upload === null) {
                return null;
            }

            if ($upload->status === MediaUploadStatus::Deleted) {
                return $upload;
            }

            if ($upload->status !== MediaUploadStatus::Staged) {
                $this->tokenError('Upload staging không còn có thể xóa');
            }

            $this->staging->delete($upload->staging_key);
            $upload->fill(['status' => MediaUploadStatus::Deleted])->save();

            return $upload->refresh();
        }, 1);
    }

    public function previewStaging(User $actor, string $token): ?StreamedResponse
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $upload = $this->uploads->findOwned($token, $actor->id);
        if ($upload === null
            || $upload->status !== MediaUploadStatus::Staged
            || $upload->expires_at->isPast()) {
            return null;
        }

        return $this->staging->response($upload->staging_key);
    }

    /** @param array<int, string> $tokens */
    public function assertConsumableTokens(User $actor, array $tokens): void
    {
        foreach (array_unique($tokens) as $token) {
            if (! is_string($token) || ! Str::isUuid($token)) {
                $this->tokenError('Upload token không hợp lệ');
            }

            $upload = $this->uploads->findOwned($token, $actor->id);
            $this->assertConsumable($upload);
        }
    }

    /**
     * @param  Closure(MediaUpload): string  $destination
     * @param  array<int, array{upload_id: int, final_key: string}>  $promotions
     */
    public function promoteOwned(User $actor, string $token, Closure $destination, array &$promotions): string
    {
        $upload = $this->uploads->lockOwned($token, $actor->id);
        $this->assertConsumable($upload);
        $destinationKey = $destination($upload);
        $finalKey = $this->publicMedia->canonicalReference($destinationKey);
        $promotions[] = ['upload_id' => $upload->id, 'final_key' => $finalKey];

        $stream = $this->staging->readStream($upload->staging_key);
        if ($stream === false) {
            throw new \RuntimeException('Unable to read the staging media object.');
        }

        try {
            $stored = $this->publicMedia->put($destinationKey, $stream, $upload->mime_type, $upload->original_name);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored->key !== $finalKey) {
            throw new \RuntimeException('Public media provider returned an unexpected canonical reference.');
        }

        $upload->fill([
            'status' => MediaUploadStatus::Promoted,
            'promoted_at' => now(),
            'final_key' => $finalKey,
            'mime_type' => $stored->mimeType ?? $upload->mime_type,
            'extension' => $stored->extension,
            'bytes' => $stored->bytes ?? $upload->bytes,
            'width' => $stored->width ?? $upload->width,
            'height' => $stored->height ?? $upload->height,
        ])->save();

        return $finalKey;
    }

    /** @param array<int, array{upload_id: int, final_key: string}> $promotions */
    public function cleanupPromotedStaging(array $promotions): void
    {
        foreach ($promotions as $promotion) {
            $upload = MediaUpload::query()->find($promotion['upload_id']);

            if ($upload === null) {
                continue;
            }

            try {
                if (! $this->staging->delete($upload->staging_key)) {
                    throw new \RuntimeException('Unable to delete the promoted staging media object.');
                }
            } catch (Throwable $exception) {
                $upload->fill(['status' => MediaUploadStatus::StagingCleanupFailed])->save();
                report($exception);
            }
        }
    }

    /** @param array<int, array{upload_id: int, final_key: string}> $promotions */
    public function compensateFailedPromotions(array $promotions): void
    {
        foreach ($promotions as $promotion) {
            $upload = MediaUpload::query()->find($promotion['upload_id']);

            try {
                $this->publicMedia->delete($promotion['final_key']);
                if ($upload !== null) {
                    $this->safeDelete($upload->staging_key);
                }
                MediaUpload::query()->whereKey($promotion['upload_id'])->update([
                    'status' => MediaUploadStatus::Deleted->value,
                    'final_key' => $promotion['final_key'],
                ]);
            } catch (Throwable $exception) {
                MediaUpload::query()->whereKey($promotion['upload_id'])->update([
                    'status' => MediaUploadStatus::CleanupFailed->value,
                    'final_key' => $promotion['final_key'],
                ]);
                report($exception);
            }
        }
    }

    /** @param array<int, string|null> $references */
    public function cleanupReplaced(array $references): void
    {
        foreach (array_unique(array_filter($references, 'is_string')) as $reference) {
            if (! $this->isOwnedCanonicalKey($reference) || $this->isReferenced($reference)) {
                continue;
            }

            $upload = MediaUpload::query()->where('final_key', $reference)->first();
            $upload?->fill(['status' => MediaUploadStatus::CleanupPending])->save();

            try {
                $this->publicMedia->delete($reference);
                $upload?->fill(['status' => MediaUploadStatus::Deleted])->save();
            } catch (Throwable $exception) {
                $upload?->fill(['status' => MediaUploadStatus::CleanupFailed])->save();
                report($exception);
            }
        }
    }

    public function assertLegacyReference(?string $reference, string $field): void
    {
        if ($reference === null || $reference === '') {
            return;
        }

        if (str_starts_with($reference, 'staging/')
            || str_starts_with($reference, 'public/')
            || str_starts_with($reference, 'cloudinary:')) {
            throw ValidationException::withMessages([
                $field => ['Object key mới phải được gửi bằng upload token thuộc sở hữu của bạn'],
            ]);
        }
    }

    /** @return array{expired: int, deleted: int, failed: int} */
    public function cleanup(int $batchSize = 100): array
    {
        $result = ['expired' => 0, 'deleted' => 0, 'failed' => 0];
        $afterId = 0;

        do {
            $batch = $this->uploads->expiredStagedBatch($batchSize, $afterId);

            foreach ($batch as $upload) {
                $afterId = $upload->id;
                try {
                    $this->staging->delete($upload->staging_key);
                    $upload->fill(['status' => MediaUploadStatus::Expired])->save();
                    $result['expired']++;
                } catch (Throwable $exception) {
                    $result['failed']++;
                    report($exception);
                }
            }
        } while ($batch->count() === $batchSize);

        $afterId = 0;
        do {
            $batch = $this->uploads->failedStagingCleanupBatch($batchSize, $afterId);

            foreach ($batch as $upload) {
                $afterId = $upload->id;
                try {
                    if (! $this->staging->delete($upload->staging_key)) {
                        throw new \RuntimeException('Unable to delete the promoted staging media object.');
                    }
                    $upload->fill(['status' => MediaUploadStatus::Promoted])->save();
                    $result['deleted']++;
                } catch (Throwable $exception) {
                    $result['failed']++;
                    report($exception);
                }
            }
        } while ($batch->count() === $batchSize);

        $afterId = 0;
        do {
            $batch = $this->uploads->pendingCleanupBatch($batchSize, $afterId);

            foreach ($batch as $upload) {
                $afterId = $upload->id;
                try {
                    if ($upload->final_key !== null) {
                        $this->publicMedia->delete($upload->final_key);
                    }
                    $this->safeDelete($upload->staging_key);
                    $upload->fill(['status' => MediaUploadStatus::Deleted])->save();
                    $result['deleted']++;
                } catch (Throwable $exception) {
                    $upload->fill(['status' => MediaUploadStatus::CleanupFailed])->save();
                    $result['failed']++;
                    report($exception);
                }
            }
        } while ($batch->count() === $batchSize);

        return $result;
    }

    /** @return array{0: 'jpg'|'png'|'webp', 1: string} */
    private function classifyImage(UploadedFile $image): array
    {
        $mimeType = (string) $image->getMimeType();

        return match ($mimeType) {
            'image/jpeg' => ['jpg', $mimeType],
            'image/png' => ['png', $mimeType],
            'image/webp' => ['webp', $mimeType],
            default => throw ValidationException::withMessages([
                'image' => ['Ảnh chỉ hỗ trợ JPG, PNG hoặc WebP.'],
            ]),
        };
    }

    /** @return array{0: int|null, 1: int|null} */
    private function dimensions(UploadedFile $image): array
    {
        $dimensions = @getimagesize($image->getPathname());

        return $dimensions === false ? [null, null] : [(int) $dimensions[0], (int) $dimensions[1]];
    }

    private function assertConsumable(?MediaUpload $upload): void
    {
        if ($upload === null) {
            $this->tokenError('Upload token không tồn tại hoặc không thuộc sở hữu của bạn');
        }

        if ($upload->status !== MediaUploadStatus::Staged) {
            $this->tokenError('Upload token đã được sử dụng hoặc không còn hiệu lực');
        }

        if ($upload->expires_at->isPast()) {
            $this->tokenError('Upload token đã hết hạn');
        }

        if (! $this->staging->exists($upload->staging_key)) {
            $this->tokenError('Không tìm thấy object staging của upload token');
        }
    }

    private function isOwnedCanonicalKey(string $key): bool
    {
        return $this->references->isOwned($key);
    }

    private function isReferenced(string $key): bool
    {
        return DB::table('product_images')->where('image_url', $key)->exists()
            || DB::table('brands')->where('logo_url', $key)->orWhere('banner_image', $key)->exists()
            || DB::table('categories')->where('image_url', $key)->exists()
            || DB::table('users')->where('avatar', $key)->exists();
    }

    private function safeDelete(string $key): void
    {
        try {
            $this->staging->delete($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function tokenError(string $message): never
    {
        throw ValidationException::withMessages(['upload_token' => [$message]]);
    }

    private function previewUrlTtlMinutes(): int
    {
        $requested = max(1, (int) config('media.staging_preview_url_ttl_minutes', 15));
        $maximum = max(1, (int) config('media.staging_preview_url_max_ttl_minutes', 60));

        return min($requested, $maximum);
    }
}
