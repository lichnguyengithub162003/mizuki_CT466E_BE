<?php

namespace App\Support\Import;

use Illuminate\Support\Str;

final class ProductOwnedEngagementReconciler
{
    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $contentGroup
     * @return array<string, mixed>
     */
    public function reconcile(array $group, array $data, ?array $contentGroup = null): array
    {
        $productIds = $this->integerList($group['candidate_product_ids'] ?? []);
        $canonicalId = (int) ($group['recommended_canonical_product_id'] ?? 0);
        $canonicalId = in_array($canonicalId, $productIds, true) ? $canonicalId : min($productIds);
        $variants = $this->sortedById($data['variants'] ?? []);
        $variantMap = $this->variantMap($productIds, $variants);
        $orderItems = $this->keyById($data['order_items'] ?? []);
        $reviews = $this->sortedById($data['reviews'] ?? []);
        $questions = $this->sortedById($data['questions'] ?? []);
        $favorites = $this->sortedById($data['favorites'] ?? []);
        $answers = $this->answersByQuestion($data['answers'] ?? []);

        $reviewAudit = $this->reviewAudit($reviews, $orderItems, $variantMap, $canonicalId);
        $questionAudit = $this->questionAudit($questions, $answers, $variantMap, $canonicalId);
        $favoriteAudit = $this->favoriteAudit($favorites, $canonicalId);
        $manual = $reviewAudit['manual_conflicts'] + $questionAudit['manual_conflicts'] + $favoriteAudit['manual_conflicts'];
        $variantRequired = $reviewAudit['variant_attribution_required'] + $questionAudit['variant_attribution_required'];
        $deduplicated = $reviewAudit['deduplicated'] + $questionAudit['deduplicated'] + $favoriteAudit['deduplicated'];

        $decision = match (true) {
            $manual > 0 => 'manual_review',
            $variantRequired > 0 => 'needs_variant_attribution',
            $deduplicated > 0 => 'engagement_merge_ready_with_dedup',
            default => 'engagement_merge_ready',
        };
        $blockers = [];
        if ($reviewAudit['manual_conflicts'] > 0) {
            $blockers[] = 'manual_review_collision';
        }
        if ($questionAudit['manual_conflicts'] > 0) {
            $blockers[] = 'manual_question_collision';
        }
        if ($reviewAudit['preserve_as_separate'] > 0) {
            $blockers[] = 'reviews_user_product_unique_blocks_separate_variant_reviews';
        }
        if ($questionAudit['variant_specific'] > 0) {
            $blockers[] = 'product_questions_has_no_product_variant_id';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return [
            'group_identifier' => (string) ($group['group_identifier'] ?? ''),
            'candidate_product_ids' => $productIds,
            'canonical_product_id_for_estimate' => $canonicalId,
            't1_5_merge_decision' => $contentGroup['recommended_merge_decision'] ?? null,
            't1_5_reasons' => $contentGroup['reasons'] ?? [],
            'reviews' => $this->publicAudit($reviewAudit),
            'questions' => $this->publicAudit($questionAudit),
            'favorites' => $this->publicAudit($favoriteAudit),
            'variant_attribution_opportunities' => [
                'reviews' => $reviewAudit['attribution_summary'],
                'questions' => $questionAudit['attribution_summary'],
            ],
            'unique_key_conflicts' => [
                'reviews_user_product' => $reviewAudit['unique_key_conflicts'],
                'questions_product_source_external_key' => $questionAudit['unique_key_conflicts'],
                'favorites_user_product' => $favoriteAudit['unique_key_conflicts'],
            ],
            'blockers' => $blockers,
            'resulting_engagement_decision' => $decision,
            '_metrics' => [
                'has_engagement' => count($reviews) + count($questions) + count($favorites) > 0 ? 1 : 0,
                'transferred' => $reviewAudit['transferred'] + $questionAudit['transferred'] + $favoriteAudit['transferred'],
                'deduplicated' => $deduplicated,
                'variant_attributable' => $reviewAudit['variant_attributable'] + $questionAudit['variant_attributable'],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $reviews @param array<int, array<string, mixed>> $orderItems @param array<int, list<array<string, mixed>>> $variantMap @return array<string, mixed> */
    private function reviewAudit(array $reviews, array $orderItems, array $variantMap, int $canonicalId): array
    {
        $attributions = [];
        foreach ($reviews as $review) {
            $attributions[$review['id']] = $this->reviewAttribution($review, $orderItems, $variantMap);
        }

        $actions = [];
        $handled = [];
        $deduplicateIds = [];
        $userGroups = [];
        foreach ($reviews as $review) {
            if ($review['user_id'] !== null) {
                $userGroups[(string) $review['user_id']][] = $review;
            }
        }

        foreach ($userGroups as $userId => $rows) {
            if (count(array_unique(array_column($rows, 'product_id'))) < 2) {
                continue;
            }
            $classification = $this->reviewCollisionClassification($rows, $attributions);
            $relationship = $this->reviewContentRelationship($rows);
            $recordIds = $this->integerList(array_column($rows, 'id'));
            $actions[] = $this->action(
                $classification,
                $recordIds,
                $rows,
                'same_user_would_collide_after_canonicalization',
                [
                    'content_relationship' => $relationship,
                    'user_id' => is_numeric($userId) ? (int) $userId : $userId,
                    'unique_key_collision' => true,
                ],
            );
            foreach ($recordIds as $id) {
                $handled[$id] = true;
            }
            if ($classification === 'deduplicate_safe') {
                $deduplicateIds = array_merge($deduplicateIds, $this->deduplicationLosers($rows, $canonicalId));
            }
        }

        $importedGroups = [];
        foreach ($reviews as $review) {
            if (isset($handled[$review['id']]) || $review['user_id'] !== null) {
                continue;
            }
            $importedGroups[$this->reviewNormalizedFingerprint($review)][] = $review;
        }
        foreach ($importedGroups as $rows) {
            if (count($rows) < 2 || count(array_unique(array_column($rows, 'product_id'))) < 2) {
                continue;
            }
            $recordIds = $this->integerList(array_column($rows, 'id'));
            $actions[] = $this->action(
                'deduplicate_safe',
                $recordIds,
                $rows,
                'same_imported_review_content',
                ['content_relationship' => $this->reviewContentRelationship($rows)],
            );
            foreach ($recordIds as $id) {
                $handled[$id] = true;
            }
            $deduplicateIds = array_merge($deduplicateIds, $this->deduplicationLosers($rows, $canonicalId));
        }

        foreach ($reviews as $review) {
            if (! isset($handled[$review['id']])) {
                $actions[] = $this->action('transfer_safe', [(int) $review['id']], [$review], 'no_unique_key_collision');
            }
        }

        return $this->finalizeAudit(
            $reviews,
            $actions,
            $attributions,
            $deduplicateIds,
            $canonicalId,
            'preserve_as_separate_variant_reviews',
        );
    }

    /** @param list<array<string, mixed>> $questions @param array<int, list<array<string, mixed>>> $answers @param array<int, list<array<string, mixed>>> $variantMap @return array<string, mixed> */
    private function questionAudit(array $questions, array $answers, array $variantMap, int $canonicalId): array
    {
        $attributions = [];
        foreach ($questions as $question) {
            $attributions[$question['id']] = $this->sourceProductAttribution((int) $question['product_id'], $variantMap, 'source_identity');
        }

        $actions = [];
        $handled = [];
        $deduplicateIds = [];
        $keyGroups = [];
        foreach ($questions as $question) {
            if ($question['source'] !== null && $question['external_key'] !== null) {
                $keyGroups[$question['source'].'|'.$question['external_key']][] = $question;
            }
        }

        foreach ($keyGroups as $key => $rows) {
            if (count(array_unique(array_column($rows, 'product_id'))) < 2) {
                continue;
            }
            $sameContent = count(array_unique(array_map(fn (array $row): string => $this->questionNormalizedFingerprint($row, $answers), $rows))) === 1;
            $classification = $sameContent
                ? 'deduplicate_safe'
                : ($this->questionsAreVariantSpecific($rows, $variantMap) ? 'variant_specific' : 'manual_conflict');
            $ids = $this->integerList(array_column($rows, 'id'));
            $actions[] = $this->action(
                $classification,
                $ids,
                $rows,
                $sameContent ? 'duplicate_source_question' : 'same_external_key_different_content',
                ['external_key' => $key, 'unique_key_collision' => true],
            );
            foreach ($ids as $id) {
                $handled[$id] = true;
            }
            if ($classification === 'deduplicate_safe') {
                $deduplicateIds = array_merge($deduplicateIds, $this->deduplicationLosers($rows, $canonicalId));
            }
        }

        $contentGroups = [];
        foreach ($questions as $question) {
            if (! isset($handled[$question['id']])) {
                $contentGroups[$this->questionNormalizedFingerprint($question, $answers)][] = $question;
            }
        }
        foreach ($contentGroups as $rows) {
            if (count($rows) < 2 || count(array_unique(array_column($rows, 'product_id'))) < 2) {
                continue;
            }
            $ids = $this->integerList(array_column($rows, 'id'));
            $actions[] = $this->action('deduplicate_safe', $ids, $rows, 'duplicate_question_content');
            foreach ($ids as $id) {
                $handled[$id] = true;
            }
            $deduplicateIds = array_merge($deduplicateIds, $this->deduplicationLosers($rows, $canonicalId));
        }
        foreach ($questions as $question) {
            if (! isset($handled[$question['id']])) {
                $actions[] = $this->action('transfer_safe', [(int) $question['id']], [$question], 'no_unique_key_collision');
            }
        }

        return $this->finalizeAudit($questions, $actions, $attributions, $deduplicateIds, $canonicalId, 'variant_specific');
    }

    /** @param list<array<string, mixed>> $favorites @return array<string, mixed> */
    private function favoriteAudit(array $favorites, int $canonicalId): array
    {
        $actions = [];
        $handled = [];
        $deduplicateIds = [];
        $byUser = [];
        foreach ($favorites as $favorite) {
            $byUser[(string) $favorite['user_id']][] = $favorite;
        }
        foreach ($byUser as $rows) {
            if (count(array_unique(array_column($rows, 'product_id'))) < 2) {
                continue;
            }
            $ids = $this->integerList(array_column($rows, 'id'));
            $actions[] = $this->action(
                'deduplicate_safe',
                $ids,
                $rows,
                'product_family_favorite',
                ['unique_key_collision' => true],
            );
            foreach ($ids as $id) {
                $handled[$id] = true;
            }
            $deduplicateIds = array_merge($deduplicateIds, $this->deduplicationLosers($rows, $canonicalId));
        }
        foreach ($favorites as $favorite) {
            if (! isset($handled[$favorite['id']])) {
                $actions[] = $this->action('transfer_safe', [(int) $favorite['id']], [$favorite], 'no_unique_key_collision');
            }
        }

        return $this->finalizeAudit($favorites, $actions, [], $deduplicateIds, $canonicalId, 'never');
    }

    /** @param list<array<string, mixed>> $records @param list<array<string, mixed>> $actions @param array<int, array<string, mixed>> $attributions @param list<int> $deduplicateIds @return array<string, mixed> */
    private function finalizeAudit(array $records, array $actions, array $attributions, array $deduplicateIds, int $canonicalId, string $variantClassification): array
    {
        usort($actions, static fn (array $left, array $right): int => [$left['record_ids'][0], $left['classification']] <=> [$right['record_ids'][0], $right['classification']]);
        $deduplicateIds = $this->integerList($deduplicateIds);
        $classificationCounts = array_count_values(array_column($actions, 'classification'));
        $attributionSummary = [
            'attributable_to_one_variant' => 0,
            'ambiguous' => 0,
            'no_matching_variant' => 0,
        ];
        foreach ($attributions as $attribution) {
            $attributionSummary[$attribution['status']]++;
        }
        $variantAttributable = $attributionSummary['attributable_to_one_variant'];
        $variantRequired = $variantAttributable;
        if ($variantClassification !== 'never') {
            foreach ($records as $record) {
                if ($variantClassification === 'preserve_as_separate_variant_reviews' && $record['product_variant_id'] !== null) {
                    $variantRequired--;
                }
            }
        } else {
            $variantRequired = 0;
        }
        $variantRequired = max(0, $variantRequired);
        $transferred = count(array_filter(
            $records,
            static fn (array $record): bool => (int) $record['product_id'] !== $canonicalId
                && ! in_array((int) $record['id'], $deduplicateIds, true),
        ));

        return [
            'count' => count($records),
            'counts_by_product' => $this->countsByProduct($records),
            'actions' => $actions,
            'variant_attribution_by_record' => $attributions,
            'attribution_summary' => $attributionSummary,
            'unique_key_conflicts' => array_values(array_filter(
                $actions,
                static fn (array $action): bool => ($action['unique_key_collision'] ?? false) === true,
            )),
            'transferred' => $transferred,
            'deduplicated' => count($deduplicateIds),
            'variant_attributable' => $variantAttributable,
            'variant_attribution_required' => $variantRequired
                + (int) ($classificationCounts[$variantClassification] ?? 0),
            'manual_conflicts' => (int) ($classificationCounts['manual_conflict'] ?? 0),
            'preserve_as_separate' => (int) ($classificationCounts['preserve_as_separate_variant_reviews'] ?? 0),
            'variant_specific' => (int) ($classificationCounts['variant_specific'] ?? 0),
        ];
    }

    /** @param list<array<string, mixed>> $rows @param array<int, array<string, mixed>> $attributions */
    private function reviewCollisionClassification(array $rows, array $attributions): string
    {
        if (count(array_unique(array_map($this->reviewExactFingerprint(...), $rows))) === 1
            || count(array_unique(array_map($this->reviewNormalizedFingerprint(...), $rows))) === 1) {
            return 'deduplicate_safe';
        }

        $variantIds = [];
        foreach ($rows as $row) {
            $attribution = $attributions[$row['id']];
            if ($attribution['status'] !== 'attributable_to_one_variant') {
                return 'manual_conflict';
            }
            $variantIds[] = $attribution['variant_id'];
        }

        return count(array_unique($variantIds)) === count($variantIds)
            ? 'preserve_as_separate_variant_reviews'
            : 'manual_conflict';
    }

    /** @param list<array<string, mixed>> $rows */
    private function reviewContentRelationship(array $rows): string
    {
        if (count(array_unique(array_map($this->reviewExactFingerprint(...), $rows))) === 1) {
            return 'identical';
        }
        if (count(array_unique(array_map($this->reviewNormalizedFingerprint(...), $rows))) === 1) {
            return 'near_duplicate';
        }

        return 'genuinely_different';
    }

    /** @param array<string, mixed> $review @param array<int, array<string, mixed>> $orderItems @param array<int, list<array<string, mixed>>> $variantMap @return array<string, mixed> */
    private function reviewAttribution(array $review, array $orderItems, array $variantMap): array
    {
        if ($review['product_variant_id'] !== null) {
            return ['status' => 'attributable_to_one_variant', 'variant_id' => (int) $review['product_variant_id'], 'method' => 'existing_product_variant_id'];
        }
        $orderItem = $review['order_item_id'] !== null ? ($orderItems[(int) $review['order_item_id']] ?? null) : null;
        if ($orderItem !== null && $orderItem['product_variant_id'] !== null) {
            return ['status' => 'attributable_to_one_variant', 'variant_id' => (int) $orderItem['product_variant_id'], 'method' => 'order_item'];
        }

        return $this->sourceProductAttribution((int) $review['product_id'], $variantMap, 'source_identity');
    }

    /** @param array<int, list<array<string, mixed>>> $variantMap @return array<string, mixed> */
    private function sourceProductAttribution(int $productId, array $variantMap, string $method): array
    {
        $variants = $variantMap[$productId] ?? [];
        if (count($variants) === 1) {
            return ['status' => 'attributable_to_one_variant', 'variant_id' => (int) $variants[0]['id'], 'method' => $method];
        }

        return [
            'status' => $variants === [] ? 'no_matching_variant' : 'ambiguous',
            'variant_id' => null,
            'method' => $method,
        ];
    }

    /** @param list<array<string, mixed>> $rows @param array<int, list<array<string, mixed>>> $variantMap */
    private function questionsAreVariantSpecific(array $rows, array $variantMap): bool
    {
        $variantIds = [];
        foreach ($rows as $row) {
            $variants = $variantMap[(int) $row['product_id']] ?? [];
            if (count($variants) !== 1) {
                return false;
            }
            $tokens = array_filter(array_merge(
                [(string) ($variants[0]['barcode'] ?? '')],
                array_map('strval', array_values($variants[0]['attributes'] ?? [])),
            ));
            $question = $this->normalizeText((string) $row['question']);
            if (! array_filter($tokens, fn (string $token): bool => str_contains($question, $this->normalizeText($token)))) {
                return false;
            }
            $variantIds[] = $variants[0]['id'];
        }

        return count(array_unique($variantIds)) === count($variantIds);
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function action(string $classification, array $recordIds, array $rows, string $reason, array $extra = []): array
    {
        $productIds = $this->integerList(array_column($rows, 'product_id'));

        return array_merge([
            'classification' => $classification,
            'record_ids' => $recordIds,
            'product_ids' => $productIds,
            'reason' => $reason,
            'record_evidence' => array_map(fn (array $row): array => $this->recordEvidence($row), $rows),
        ], $extra);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordEvidence(array $row): array
    {
        $content = $row['comment'] ?? $row['question'] ?? null;

        return array_filter([
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'user_id' => $row['user_id'] ?? null,
            'product_variant_id' => $row['product_variant_id'] ?? null,
            'order_item_id' => $row['order_item_id'] ?? null,
            'rating' => $row['rating'] ?? null,
            'source' => $row['source'] ?? null,
            'source_key' => $row['source_key'] ?? $row['external_key'] ?? null,
            'content_fingerprint' => $content === null ? null : $this->fingerprint($this->normalizeText((string) $content)),
            'content_preview' => $content === null ? null : Str::limit((string) $content, 120, '…'),
            'occurred_at' => $row['asked_at'] ?? $row['source_date'] ?? $row['created_at'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param list<array<string, mixed>> $rows @return list<int> */
    private function deduplicationLosers(array $rows, int $canonicalId): array
    {
        usort($rows, static fn (array $left, array $right): int => [
            (int) $left['product_id'] === $canonicalId ? 0 : 1,
            (int) $left['id'],
        ] <=> [
            (int) $right['product_id'] === $canonicalId ? 0 : 1,
            (int) $right['id'],
        ]);

        return array_map('intval', array_column(array_slice($rows, 1), 'id'));
    }

    /** @param array<string, mixed> $review */
    private function reviewExactFingerprint(array $review): string
    {
        return $this->fingerprint([
            $review['rating'], $review['title'], $review['comment'], $review['source'],
            $review['source_author_name'], $review['source_date'], $review['variant_purchased'],
            $this->decodedJson($review['images'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $review */
    private function reviewNormalizedFingerprint(array $review): string
    {
        return $this->fingerprint([
            (int) $review['rating'],
            $this->normalizeText((string) ($review['title'] ?? '')),
            $this->normalizeText((string) ($review['comment'] ?? '')),
            $this->normalizeText((string) ($review['source_author_name'] ?? '')),
            $this->normalizeText((string) ($review['source_date'] ?? '')),
            $this->normalizeText((string) ($review['variant_purchased'] ?? '')),
            $this->decodedJson($review['images'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $question @param array<int, list<array<string, mixed>>> $answers */
    private function questionNormalizedFingerprint(array $question, array $answers): string
    {
        $answerContent = array_map(
            fn (array $answer): array => [
                $this->normalizeText((string) $answer['answer']),
                $this->normalizeText((string) ($answer['author_name'] ?? '')),
            ],
            $answers[(int) $question['id']] ?? [],
        );

        return $this->fingerprint([$this->normalizeText((string) $question['question']), $answerContent]);
    }

    /** @param list<array<string, mixed>> $variants @return array<int, list<array<string, mixed>>> */
    private function variantMap(array $productIds, array $variants): array
    {
        $map = array_fill_keys($productIds, []);
        foreach ($variants as $variant) {
            $sourceProductId = (int) ($variant['source_product_id'] ?? 0);
            if (isset($map[$sourceProductId])) {
                $map[$sourceProductId][] = $variant;
            }
        }

        return $map;
    }

    /** @param list<array<string, mixed>> $answers @return array<int, list<array<string, mixed>>> */
    private function answersByQuestion(array $answers): array
    {
        $result = [];
        foreach ($this->sortedById($answers) as $answer) {
            $result[(int) $answer['product_question_id']][] = $answer;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $records @return array<int, int> */
    private function countsByProduct(array $records): array
    {
        $counts = [];
        foreach ($records as $record) {
            $counts[(int) $record['product_id']] = ($counts[(int) $record['product_id']] ?? 0) + 1;
        }
        ksort($counts, SORT_NUMERIC);

        return $counts;
    }

    /** @param array<string, mixed> $audit @return array<string, mixed> */
    private function publicAudit(array $audit): array
    {
        return $audit;
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = Str::lower($value);

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '');
    }

    private function decodedJson(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as &$item) {
            $item = $this->canonical($item);
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @param array<mixed> $values @return list<int> */
    private function integerList(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /** @param array<mixed> $rows @return list<array<string, mixed>> */
    private function sortedById(array $rows): array
    {
        usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return array_values($rows);
    }

    /** @param array<mixed> $rows @return array<int, array<string, mixed>> */
    private function keyById(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }
}
