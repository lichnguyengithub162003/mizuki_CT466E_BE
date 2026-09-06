<?php

namespace App\Repositories;

use App\Enums\MediaUploadStatus;
use App\Models\MediaUpload;
use Illuminate\Database\Eloquent\Collection;

/** @extends BaseRepository<MediaUpload> */
class MediaUploadRepository extends BaseRepository
{
    public function __construct(MediaUpload $model)
    {
        parent::__construct($model);
    }

    /** @param array<string, mixed> $attributes */
    public function createUpload(array $attributes): MediaUpload
    {
        return $this->query()->create($attributes);
    }

    public function findOwned(string $token, int $actorId): ?MediaUpload
    {
        return $this->query()
            ->where('upload_token', $token)
            ->where('uploaded_by_user_id', $actorId)
            ->first();
    }

    public function lockOwned(string $token, int $actorId): ?MediaUpload
    {
        return $this->query()
            ->where('upload_token', $token)
            ->where('uploaded_by_user_id', $actorId)
            ->lockForUpdate()
            ->first();
    }

    /** @return Collection<int, MediaUpload> */
    public function expiredStagedBatch(int $limit, int $afterId = 0): Collection
    {
        return $this->query()
            ->where('status', MediaUploadStatus::Staged)
            ->where('expires_at', '<=', now())
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, MediaUpload> */
    public function pendingCleanupBatch(int $limit, int $afterId = 0): Collection
    {
        return $this->query()
            ->whereIn('status', [MediaUploadStatus::CleanupPending, MediaUploadStatus::CleanupFailed])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, MediaUpload> */
    public function failedStagingCleanupBatch(int $limit, int $afterId = 0): Collection
    {
        return $this->query()
            ->where('status', MediaUploadStatus::StagingCleanupFailed)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
