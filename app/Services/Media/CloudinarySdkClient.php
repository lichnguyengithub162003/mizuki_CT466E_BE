<?php

namespace App\Services\Media;

use Cloudinary\Api\ApiResponse;
use Cloudinary\Cloudinary;
use RuntimeException;

final class CloudinarySdkClient implements CloudinaryClientContract
{
    public function upload(mixed $contents, array $options): array
    {
        return $this->response($this->sdk()->uploadApi()->upload($contents, $options));
    }

    public function destroy(string $publicId, array $options): array
    {
        return $this->response($this->sdk()->uploadApi()->destroy($publicId, $options));
    }

    private function sdk(): Cloudinary
    {
        $url = config('media.cloudinary_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('CLOUDINARY_URL must be configured before Cloudinary media can be used.');
        }

        return new Cloudinary($url);
    }

    /** @return array<string, mixed> */
    private function response(ApiResponse $response): array
    {
        return $response->getArrayCopy();
    }
}
