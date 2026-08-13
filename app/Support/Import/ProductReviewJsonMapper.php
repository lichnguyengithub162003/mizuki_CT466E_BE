<?php

namespace App\Support\Import;

use Carbon\CarbonImmutable;
use Throwable;

class ProductReviewJsonMapper
{
    /**
     * @return array{
     *     records: list<array<string, mixed>>,
     *     stats: array{skipped: int, duplicate_collapsed: int, failed: int}
     * }
     */
    public function map(string $sourceProductId, mixed $items): array
    {
        $result = [
            'records' => [],
            'stats' => ['skipped' => 0, 'duplicate_collapsed' => 0, 'failed' => 0],
        ];

        if ($items === null) {
            return $result;
        }

        if (! is_array($items) || ! array_is_list($items)) {
            $result['stats']['failed']++;

            return $result;
        }

        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                $result['stats']['failed']++;

                continue;
            }

            $rating = $this->rating($item['ratingScore'] ?? null);
            $comment = $this->nullableString($item['comment'] ?? null);

            if ($rating === null || $comment === null) {
                $result['stats']['skipped']++;

                continue;
            }

            $author = $this->limitedString($item['author'] ?? null);
            $date = $this->limitedString($item['date'] ?? null);
            $variantPurchased = $this->limitedString($item['variantPurchased'] ?? null);
            $sourceKey = $this->sourceKey(
                $sourceProductId,
                $author,
                $rating,
                $comment,
                $date,
                $variantPurchased,
            );

            if (isset($seen[$sourceKey])) {
                $result['stats']['duplicate_collapsed']++;

                continue;
            }

            $seen[$sourceKey] = true;
            $result['records'][] = [
                'source' => 'hasaki',
                'source_key' => $sourceKey,
                'source_author_name' => $author,
                'source_verified_purchase' => is_bool($item['verifiedPurchase'] ?? null)
                    ? $item['verifiedPurchase']
                    : null,
                'source_date' => $date,
                'variant_purchased' => $variantPurchased,
                'images' => $this->images($item['images'] ?? null),
                'mizuki_response_content' => $this->normalizeReply($item['hasakiReply'] ?? null),
                'user_id' => null,
                'order_item_id' => null,
                'rating' => $rating,
                'title' => $this->limitedString($item['ratingLabel'] ?? null),
                'comment' => $comment,
                'is_visible' => true,
                'created_at' => $this->parsedDate($date),
            ];
        }

        return $result;
    }

    private function rating(mixed $value): ?int
    {
        if (is_int($value)) {
            $rating = $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $rating = (int) $value;
        } elseif (is_string($value) && preg_match('/^[1-5]$/', trim($value)) === 1) {
            $rating = (int) trim($value);
        } else {
            return null;
        }

        return $rating >= 1 && $rating <= 5 ? $rating : null;
    }

    /**
     * @return list<string>
     */
    private function images(mixed $images): array
    {
        if (! is_array($images) || ! array_is_list($images)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($images as $image) {
            if (! is_string($image)) {
                continue;
            }

            $image = trim($image);

            if ($image === '' || isset($seen[$image])) {
                continue;
            }

            $seen[$image] = true;
            $normalized[] = $image;
        }

        return $normalized;
    }

    private function normalizeReply(mixed $reply): ?string
    {
        $reply = $this->nullableString($reply);

        if ($reply === null) {
            return null;
        }

        $reply = preg_replace('/hasaki\.vn/iu', 'Mizuki', $reply) ?? $reply;
        $reply = preg_replace('/hasaki/iu', 'Mizuki', $reply) ?? $reply;

        return trim($reply);
    }

    private function parsedDate(?string $date): ?string
    {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}, \d{2}:\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d, H:i', $date, config('app.timezone'));

            return $parsed->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function sourceKey(
        string $sourceProductId,
        ?string $author,
        int $rating,
        string $comment,
        ?string $date,
        ?string $variantPurchased,
    ): string {
        return hash('sha256', implode("\x1F", [
            $sourceProductId,
            $this->identityText($author),
            (string) $rating,
            $this->identityText($comment),
            $this->identityText($date),
            $this->identityText($variantPurchased),
        ]));
    }

    private function identityText(?string $value): string
    {
        $value = mb_strtolower(trim($value ?? ''));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function limitedString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_substr($value, 0, 255);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
