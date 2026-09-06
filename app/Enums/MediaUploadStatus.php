<?php

namespace App\Enums;

enum MediaUploadStatus: string
{
    case Staged = 'staged';
    case Promoted = 'promoted';
    case Deleted = 'deleted';
    case Expired = 'expired';
    case CleanupPending = 'cleanup_pending';
    case CleanupFailed = 'cleanup_failed';
    case StagingCleanupFailed = 'staging_cleanup_failed';
}
