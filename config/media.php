<?php

return [
    /*
     * Logical media roles. Local development uses Laravel's existing local
     * disks unless Cloudinary is explicitly selected for public media.
     */
    'public_driver' => env('MEDIA_PUBLIC_DRIVER', 'local'),
    'public_disk' => env('MEDIA_PUBLIC_DISK', 'public'),
    'private_disk' => env('MEDIA_PRIVATE_DISK', 'local'),

    'cloudinary_url' => env('CLOUDINARY_URL'),

    'staging_preview_url_ttl_minutes' => (int) env('MEDIA_STAGING_PREVIEW_TTL', 15),
    'staging_preview_url_max_ttl_minutes' => 60,

    // Hours before an abandoned staging upload becomes eligible for cleanup.
    'upload_staging_ttl_hours' => (int) env('MEDIA_UPLOAD_STAGING_TTL', 24),
];
