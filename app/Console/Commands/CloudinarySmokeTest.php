<?php

namespace App\Console\Commands;

use App\Services\Media\CloudinarySmokeReference;
use App\Services\Media\MediaKeyGenerator;
use App\Services\Media\MediaObject;
use App\Services\Media\PublicMediaServiceContract;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class CloudinarySmokeTest extends Command
{
    protected $signature = 'media:cloudinary-smoke';

    protected $description = 'Safely upload, verify, and delete one isolated Cloudinary smoke-test image';

    public function handle(
        PublicMediaServiceContract $publicMedia,
        MediaKeyGenerator $keys,
        CloudinarySmokeReference $smokeReferences,
    ): int {
        if (config('media.public_driver', 'local') !== 'cloudinary') {
            $this->error('Refusing smoke test: MEDIA_PUBLIC_DRIVER must be cloudinary.');

            return self::FAILURE;
        }

        try {
            $this->assertCloudinaryConfiguration();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $stream = tmpfile();
        if ($stream === false) {
            $this->error('Unable to create the temporary smoke-test image.');

            return self::FAILURE;
        }

        $reference = null;
        $publicId = null;
        $cleanupRequired = false;
        $operationFailed = false;
        $cleanupFailed = false;

        try {
            $imageBytes = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            );
            if ($imageBytes === false || fwrite($stream, $imageBytes) === false || rewind($stream) === false) {
                throw new RuntimeException('Unable to prepare the temporary smoke-test image.');
            }

            $key = $keys->cloudinarySmoke();
            $reference = $publicMedia->canonicalReference($key);
            $publicId = $smokeReferences->publicId($reference);
            $cleanupRequired = true;

            $object = $publicMedia->put($key, $stream, 'image/png', 'cloudinary-smoke.png');
            $this->assertUploadedObject($object, $reference);
            $deliveryUrl = $publicMedia->url($reference);
            $this->assertDeliveryUrl($deliveryUrl, $publicId);

            $this->table(['Field', 'Value'], [
                ['canonical reference', $reference],
                ['public_id', $publicId],
                ['delivery URL', $deliveryUrl],
                ['bytes', $object->bytes ?? 'unavailable'],
                ['width', $object->width ?? 'unavailable'],
                ['height', $object->height ?? 'unavailable'],
                ['format', $object->extension !== '' ? $object->extension : 'unavailable'],
            ]);
            $this->info('Cloudinary upload and delivery-reference verification succeeded.');
        } catch (Throwable $exception) {
            $operationFailed = true;
            $this->error('Cloudinary smoke test failed: '.$exception->getMessage());
        } finally {
            if ($cleanupRequired && is_string($reference)) {
                try {
                    $smokeReferences->publicId($reference);
                    if (! $publicMedia->delete($reference)) {
                        throw new RuntimeException('Cloudinary did not confirm smoke asset deletion.');
                    }
                    $this->info('Cleanup succeeded.');
                } catch (Throwable $exception) {
                    $cleanupFailed = true;
                    $this->error('Cleanup failed: '.$exception->getMessage());
                }
            }

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $operationFailed || $cleanupFailed ? self::FAILURE : self::SUCCESS;
    }

    private function assertCloudinaryConfiguration(): void
    {
        $url = config('media.cloudinary_url');
        $parts = is_string($url) && trim($url) !== '' ? parse_url($url) : false;

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'cloudinary'
            || ! is_string($parts['user'] ?? null)
            || ($parts['user'] ?? '') === ''
            || ! is_string($parts['pass'] ?? null)
            || ($parts['pass'] ?? '') === ''
            || ! is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === '') {
            throw new RuntimeException(
                'Refusing smoke test: CLOUDINARY_URL must use cloudinary://API_KEY:API_SECRET@CLOUD_NAME format.',
            );
        }
    }

    private function assertUploadedObject(MediaObject $object, string $reference): void
    {
        if ($object->key !== $reference) {
            throw new RuntimeException('Cloudinary returned an unexpected canonical reference.');
        }
    }

    private function assertDeliveryUrl(string $url, string $publicId): void
    {
        if (! str_starts_with($url, 'https://')
            || ! str_contains($url, '/image/upload/')
            || ! str_contains($url, '/f_auto/q_auto/')
            || ! str_ends_with($url, $publicId)) {
            throw new RuntimeException('Generated Cloudinary delivery URL failed safety verification.');
        }
    }
}
