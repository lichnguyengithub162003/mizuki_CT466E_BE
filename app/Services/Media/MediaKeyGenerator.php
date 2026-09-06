<?php

namespace App\Services\Media;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class MediaKeyGenerator
{
    public function productGallery(int $productId, string $extension): string
    {
        return $this->entityKey("public/products/{$this->id($productId)}/gallery", $extension);
    }

    public function productVariant(int $productId, int $variantId, string $extension): string
    {
        return $this->entityKey(
            "public/products/{$this->id($productId)}/variants/{$this->id($variantId)}",
            $extension,
        );
    }

    public function brandLogo(int $brandId, string $extension): string
    {
        return $this->entityKey("public/brands/{$this->id($brandId)}/logo", $extension);
    }

    public function brandBanner(int $brandId, string $extension): string
    {
        return $this->entityKey("public/brands/{$this->id($brandId)}/banner", $extension);
    }

    public function category(int $categoryId, string $extension): string
    {
        return $this->entityKey("public/categories/{$this->id($categoryId)}", $extension);
    }

    public function service(int $serviceId, string $extension): string
    {
        return $this->entityKey("public/services/{$this->id($serviceId)}", $extension);
    }

    public function branch(int $branchId, string $extension): string
    {
        return $this->entityKey("public/branches/{$this->id($branchId)}", $extension);
    }

    public function promotion(int $promotionId, string $extension): string
    {
        return $this->entityKey("public/promotions/{$this->id($promotionId)}", $extension);
    }

    public function avatar(int $userId, string $extension): string
    {
        return $this->entityKey("public/users/{$this->id($userId)}/avatar", $extension);
    }

    public function cloudinarySmoke(string $extension = 'png'): string
    {
        return $this->entityKey('public/system/smoke', $extension);
    }

    public function reviewImage(int $reviewId, string $extension): string
    {
        return $this->entityKey("public/reviews/{$this->id($reviewId)}/images", $extension);
    }

    public function reviewVideo(int $reviewId, string $extension): string
    {
        return $this->entityKey("public/reviews/{$this->id($reviewId)}/videos", $extension);
    }

    public function questionMedia(int $questionId, string $extension): string
    {
        return $this->entityKey("public/questions/{$this->id($questionId)}/media", $extension);
    }

    public function refundImage(int $refundId, string $extension): string
    {
        return $this->entityKey("private/refunds/{$this->id($refundId)}/evidence/images", $extension);
    }

    public function refundVideo(int $refundId, string $extension): string
    {
        return $this->entityKey("private/refunds/{$this->id($refundId)}/evidence/videos", $extension);
    }

    public function orderInvoice(int $orderId): string
    {
        return $this->entityKey("private/orders/{$this->id($orderId)}/invoices", 'pdf');
    }

    public function orderDocument(int $orderId, string $extension): string
    {
        return $this->entityKey("private/orders/{$this->id($orderId)}/documents", $extension);
    }

    public function posReceipt(int $sessionId): string
    {
        return $this->entityKey("private/pos/{$this->id($sessionId)}/receipts", 'pdf');
    }

    public function appointment(int $appointmentId, string $extension): string
    {
        return $this->entityKey("private/appointments/{$this->id($appointmentId)}/media", $extension);
    }

    public function export(int $userId, string $jobId, string $extension): string
    {
        return $this->entityKey(
            "private/exports/{$this->id($userId)}/{$this->segment($jobId)}",
            $extension,
        );
    }

    public function import(int|string $actor, string $jobId, string $extension): string
    {
        $actorSegment = is_int($actor) ? (string) $this->id($actor) : $this->segment($actor);

        return $this->entityKey(
            "private/imports/{$actorSegment}/{$this->segment($jobId)}",
            $extension,
        );
    }

    public function staging(int $actorId, string $uploadToken, string $extension): string
    {
        return $this->entityKey(
            "staging/{$this->id($actorId)}/{$this->segment($uploadToken)}",
            $extension,
        );
    }

    public function assertValid(string $key): string
    {
        $decoded = rawurldecode($key);

        if ($key === ''
            || $decoded !== $key
            || str_contains($key, "\0")
            || str_contains($key, '\\')
            || str_starts_with($key, '/')
            || str_contains($key, '//')
            || preg_match('~(?:^|/)\.\.?(/|$)~', $key) === 1
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9/_\-.]*$~', $key) !== 1
            || ! $this->hasAllowedPrefix($key)) {
            throw new InvalidArgumentException('Invalid or unsupported media object key.');
        }

        return $key;
    }

    private function entityKey(string $directory, string $extension): string
    {
        $extension = $this->extension($extension);

        return $this->assertValid($directory.'/'.Str::uuid()->toString().'.'.$extension);
    }

    private function extension(string $extension): string
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        $extension = match ($extension) {
            'jpeg', 'jpe' => 'jpg',
            default => $extension,
        };

        if ($extension === '' || preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
            throw new InvalidArgumentException('Invalid media extension.');
        }

        return $extension;
    }

    private function id(int $id): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Media owner IDs must be positive integers.');
        }

        return $id;
    }

    private function segment(string $segment): string
    {
        if ($segment === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $segment) !== 1) {
            throw new InvalidArgumentException('Invalid media key segment.');
        }

        return $segment;
    }

    private function hasAllowedPrefix(string $key): bool
    {
        return str_starts_with($key, 'public/')
            || str_starts_with($key, 'private/')
            || str_starts_with($key, 'staging/');
    }
}
