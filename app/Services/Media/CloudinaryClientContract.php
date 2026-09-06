<?php

namespace App\Services\Media;

interface CloudinaryClientContract
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function upload(mixed $contents, array $options): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function destroy(string $publicId, array $options): array;
}
