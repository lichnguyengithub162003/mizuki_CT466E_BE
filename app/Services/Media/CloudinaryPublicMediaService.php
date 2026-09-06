<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

final class CloudinaryPublicMediaService implements PublicMediaServiceContract
{
    public function __construct(
        private readonly CloudinaryClientContract $client,
        private readonly PublicMediaReference $references,
        private readonly LocalPublicMediaService $local,
        private readonly MediaRenditionPresets $renditions,
    ) {}

    public function put(
        string $key,
        mixed $contents,
        ?string $mimeType = null,
        ?string $originalName = null,
        array $options = [],
    ): MediaObject {
        $reference = $this->canonicalReference($key);
        $publicId = $this->references->cloudinaryPublicId($reference);
        $response = $this->client->upload($this->uploadContents($contents), array_merge($options, [
            'public_id' => $publicId,
            'resource_type' => 'image',
            'type' => 'upload',
            'overwrite' => false,
            'unique_filename' => false,
            'use_filename' => false,
        ]));

        if (($response['public_id'] ?? null) !== $publicId || ($response['resource_type'] ?? 'image') !== 'image') {
            throw new RuntimeException('Cloudinary returned an unexpected public media identifier.');
        }

        return new MediaObject(
            key: $reference,
            visibility: MediaVisibility::Public,
            mimeType: is_string($response['format'] ?? null) ? 'image/'.$response['format'] : $mimeType,
            extension: is_string($response['format'] ?? null)
                ? strtolower($response['format'])
                : strtolower((string) pathinfo($key, PATHINFO_EXTENSION)),
            bytes: is_numeric($response['bytes'] ?? null) ? (int) $response['bytes'] : $this->contentBytes($contents),
            width: is_numeric($response['width'] ?? null) ? (int) $response['width'] : null,
            height: is_numeric($response['height'] ?? null) ? (int) $response['height'] : null,
            originalName: $originalName,
        );
    }

    public function canonicalReference(string $key): string
    {
        return $this->references->cloudinaryFromLocal($key);
    }

    public function delete(string $key): bool
    {
        if (str_starts_with($key, 'public/')) {
            if (! $this->references->isOwned($key)) {
                throw new InvalidArgumentException('Invalid or unsupported local public media reference.');
            }

            return $this->local->delete($key);
        }

        $publicId = $this->references->cloudinaryPublicId($key);
        $response = $this->client->destroy($publicId, [
            'resource_type' => 'image',
            'type' => 'upload',
            'invalidate' => true,
        ]);

        return in_array($response['result'] ?? null, ['ok', 'not found'], true);
    }

    public function exists(string $key): bool
    {
        throw new RuntimeException('Cloudinary existence checks are intentionally unavailable on response paths.');
    }

    public function url(string $key, ?string $preset = null): string
    {
        $publicId = $this->references->cloudinaryPublicId($key);
        $cloudName = $this->cloudName();
        $encodedPublicId = implode('/', array_map('rawurlencode', explode('/', $publicId)));
        $transformation = $this->renditions->cloudinaryTransformation($preset);
        $deliveryTransformations = $transformation === null
            ? 'f_auto/q_auto'
            : "{$transformation}/f_auto/q_auto";

        return "https://res.cloudinary.com/{$cloudName}/image/upload/{$deliveryTransformations}/{$encodedPublicId}";
    }

    private function cloudName(): string
    {
        $url = config('media.cloudinary_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('CLOUDINARY_URL must be configured before Cloudinary media can be used.');
        }

        $parts = parse_url($url);
        $cloudName = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($cloudName) || $cloudName === '') {
            throw new RuntimeException('CLOUDINARY_URL must use cloudinary://API_KEY:API_SECRET@CLOUD_NAME format.');
        }

        return rawurlencode(rawurldecode($cloudName));
    }

    private function uploadContents(mixed $contents): mixed
    {
        if ($contents instanceof UploadedFile) {
            return $contents->getPathname();
        }

        return $contents;
    }

    private function contentBytes(mixed $contents): ?int
    {
        if (is_string($contents) && ! is_file($contents)) {
            return strlen($contents);
        }

        if ($contents instanceof UploadedFile) {
            $size = $contents->getSize();

            return $size === false ? null : $size;
        }

        return null;
    }
}
