<?php

namespace App\Support\Import;

use Illuminate\Support\Str;

class ClinicServiceJsonMapper
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function map(array $record): array
    {
        $sourceId = $this->stringValue($record['sourceId'] ?? null);
        $sku = $this->stringValue($record['sku'] ?? null);
        $name = $this->stringValue($record['name'] ?? null);
        $slug = $sourceId !== ''
            ? $this->deterministicSlug($sourceId)
            : null;
        $duration = $this->resolveDuration($record);

        $base = [
            'source_id' => $sourceId,
            'sku' => $sku,
            'slug' => $slug,
            'name' => $name,
            'duration_source' => $duration['source'],
        ];

        if ($sourceId === '') {
            return $base + ['status' => 'failed', 'reason' => 'missing_source_id'];
        }

        if ($sku === '') {
            return $base + ['status' => 'failed', 'reason' => 'missing_sku'];
        }

        if ($name === '' || mb_strlen($name) > 255) {
            return $base + ['status' => 'failed', 'reason' => 'invalid_name'];
        }

        $price = $this->positiveInteger($record['price'] ?? null);

        if ($price === null) {
            return $base + ['status' => 'failed', 'reason' => 'invalid_price'];
        }

        $imageUrl = $this->nullableString($record['image'] ?? null);

        if ($imageUrl !== null && mb_strlen($imageUrl) > 255) {
            return $base + ['status' => 'failed', 'reason' => 'image_url_too_long'];
        }

        if ($duration['minutes'] === null) {
            return $base + [
                'status' => 'quarantined',
                'reason' => $duration['source'] === 'range'
                    ? 'duration_range_not_allowed'
                    : 'duration_unparseable',
            ];
        }

        $shortDescription = $this->nullableString($record['shortDescription'] ?? null)
            ?? $this->nullableString($record['subName'] ?? null);
        $categorySource = $this->nullableString($record['serviceType'] ?? null)
            ?? $this->lastCategory($record['categoryPath'] ?? null)
            ?? 'clinic';
        $category = Str::slug($categorySource, '_');

        return $base + [
            'status' => 'valid',
            'service' => [
                'category' => mb_substr($category !== '' ? $category : 'clinic', 0, 50),
                'name' => $name,
                'slug' => $slug,
                'short_description' => $shortDescription,
                'description' => $this->nullableString($record['description'] ?? null),
                'image_url' => $imageUrl,
                'duration_minutes' => $duration['minutes'],
                'price' => $price,
                'is_active' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{minutes: int|null, source: string}
     */
    private function resolveDuration(array $record): array
    {
        $numeric = $this->positiveInteger($record['durationMinutes'] ?? null);

        if ($numeric !== null) {
            return ['minutes' => $numeric, 'source' => 'numeric'];
        }

        $durationText = $this->nullableString($record['durationText'] ?? null);

        if ($durationText === null) {
            return ['minutes' => null, 'source' => 'unparseable'];
        }

        $ascii = Str::ascii($durationText);

        if (preg_match('/\|\s*\d+\s*-\s*\d+\s*phut\s*$/i', $ascii) === 1) {
            return ['minutes' => null, 'source' => 'range'];
        }

        if (preg_match('/\|\s*(\d+)\s*(?:phut|p)\s*$/i', $ascii, $matches) === 1) {
            $minutes = $this->positiveInteger($matches[1]);

            return $minutes === null
                ? ['minutes' => null, 'source' => 'unparseable']
                : ['minutes' => $minutes, 'source' => 'safely_parsed'];
        }

        return ['minutes' => null, 'source' => 'unparseable'];
    }

    private function deterministicSlug(string $sourceId): string
    {
        $safeSourceId = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $sourceId), '-');

        return 'hasaki-clinic-'.($safeSourceId !== '' ? $safeSourceId : 'unknown');
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;

            return $integer > 0 ? $integer : null;
        }

        return null;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_int($value)
            ? trim((string) $value)
            : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string !== '' ? $string : null;
    }

    private function lastCategory(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $last = end($value);

        return $this->nullableString($last);
    }
}
