<?php

namespace App\Support\Import;

use Carbon\CarbonImmutable;
use Throwable;

class ProductQuestionJsonMapper
{
    /**
     * @return list<array<string, mixed>>
     */
    public function map(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $questions = [];
        $seenKeys = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->nullableString($item['question'] ?? null);

            if ($question === null) {
                continue;
            }

            $author = $this->nullableString($item['author'] ?? null);
            $date = $this->nullableString($item['date'] ?? null);
            [$askedAt, $sourceDate] = $this->dateValues($date);
            $externalKey = $this->externalKey($author, $question, $date);

            if (isset($seenKeys[$externalKey])) {
                continue;
            }

            $seenKeys[$externalKey] = true;
            $questions[] = [
                'source' => 'hasaki',
                'external_key' => $externalKey,
                'author_name' => $author,
                'question' => $question,
                'asked_at' => $askedAt,
                'source_date' => $sourceDate,
                'sort_order' => $index,
                'answers' => $this->answers($item['answers'] ?? null),
            ];
        }

        return $questions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function answers(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $answers = [];
        $seenKeys = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = $this->nullableString($item['text'] ?? null);

            if ($text === null) {
                continue;
            }

            $author = $this->nullableString($item['author'] ?? null);
            $date = $this->nullableString($item['date'] ?? null);
            [$answeredAt, $sourceDate] = $this->dateValues($date);
            $externalKey = $this->externalKey($author, $text, $date);

            if (isset($seenKeys[$externalKey])) {
                continue;
            }

            $seenKeys[$externalKey] = true;
            $answers[] = [
                'source' => 'hasaki',
                'external_key' => $externalKey,
                'author_name' => $author,
                'answer' => $text,
                'answered_at' => $answeredAt,
                'source_date' => $sourceDate,
                'sort_order' => $index,
            ];
        }

        return $answers;
    }

    /**
     * @return array{string|null, string|null}
     */
    private function dateValues(?string $date): array
    {
        if ($date === null) {
            return [null, null];
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}, \d{2}:\d{2}$/', $date) !== 1) {
                return [null, $date];
            }

            $parsed = CarbonImmutable::createFromFormat(
                'Y-m-d, H:i',
                $date,
                config('app.timezone'),
            );

            return [$parsed->format('Y-m-d H:i:s'), null];
        } catch (Throwable) {
            return [null, $date];
        }
    }

    private function externalKey(?string $author, string $text, ?string $date): string
    {
        return hash('sha256', implode("\x1F", [$author ?? '', $text, $date ?? '']));
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
