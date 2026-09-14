<?php

namespace App\Services\Import;

use App\Repositories\Import\ProductOwnedEngagementRepository;
use App\Repositories\Import\ProductOwnedEngagementWriteRepository;
use App\Support\Import\ProductOwnedEngagementReconciler;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ProductOwnedEngagementApplyService
{
    public function __construct(
        private readonly ProductOwnedEngagementRepository $readRepository,
        private readonly ProductOwnedEngagementWriteRepository $writeRepository,
        private readonly ProductOwnedEngagementReconciler $reconciler,
    ) {}

    /**
     * @param  list<string>  $requestedGroups
     * @return array<string, mixed>
     */
    public function execute(
        string $manifestPath,
        string $contentReportPath,
        string $engagementReportPath,
        array $requestedGroups,
        bool $apply = false,
    ): array {
        $manifest = $this->indexedGroups($manifestPath, 'T1.1 normalization manifest');
        $content = $this->indexedGroups($contentReportPath, 'T1.5 content report');
        $plans = $this->indexedGroups($engagementReportPath, 'T1.6 engagement report');
        $requestedGroups = array_values(array_unique(array_filter(array_map('trim', $requestedGroups))));

        if ($apply && $requestedGroups === []) {
            throw new InvalidArgumentException('--apply requires at least one explicit --group option.');
        }
        if ($requestedGroups === []) {
            $requestedGroups = array_keys($manifest);
        }
        sort($requestedGroups, SORT_STRING);

        $availableGroups = array_values(array_intersect(
            array_keys($manifest),
            array_keys($content),
            array_keys($plans),
        ));
        $unknown = array_values(array_diff($requestedGroups, $availableGroups));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown reconciliation group: '.implode(', ', $unknown));
        }

        $result = [
            'dry_run' => ! $apply,
            'groups_requested' => count($requestedGroups),
            'groups_validated' => 0,
            'groups_reconciled' => 0,
            'already_reconciled' => 0,
            'records_attributed' => 0,
            'records_deduplicated' => 0,
            'records_transferred' => 0,
            'groups' => [],
        ];

        foreach ($requestedGroups as $identifier) {
            $plan = $plans[$identifier];
            $this->assertApproved($identifier, $plan);
            $groupResult = $this->writeRepository->transaction(
                fn (): array => $this->processGroup(
                    $manifest[$identifier],
                    $content[$identifier],
                    $plan,
                    $apply,
                ),
            );
            $result['groups_validated']++;
            $result['groups_reconciled'] += $groupResult['status'] === 'reconciled' ? 1 : 0;
            $result['already_reconciled'] += $groupResult['status'] === 'already_reconciled' ? 1 : 0;
            $result['records_attributed'] += $groupResult['reviews_attributed'];
            $result['records_deduplicated'] += $groupResult['reviews_deduplicated']
                + $groupResult['questions_deduplicated']
                + $groupResult['favorites_deduplicated'];
            $result['records_transferred'] += $groupResult['reviews_transferred']
                + $groupResult['questions_transferred']
                + $groupResult['favorites_transferred'];
            $result['groups'][] = ['group_identifier' => $identifier] + $groupResult;
        }

        return $result;
    }

    /** @param array<string, mixed> $group @param array<string, mixed> $content @param array<string, mixed> $plan @return array<string, mixed> */
    private function processGroup(array $group, array $content, array $plan, bool $apply): array
    {
        $this->writeRepository->lockGroup($group);
        if ($this->writeRepository->isAlreadyReconciled($plan)) {
            return $this->emptyGroupResult('already_reconciled');
        }

        $data = $this->readRepository->groupData($group);
        $freshPlan = $this->reconciler->reconcile($group, $data, $content);
        unset($freshPlan['_metrics']);
        if ($this->canonicalJson($freshPlan) !== $this->canonicalJson($plan)) {
            throw new RuntimeException('Stale T1.6 report or engagement state for '.$group['group_identifier'].'.');
        }

        $planned = $this->plannedGroupResult($plan, $data);
        if (! $apply) {
            return ['status' => 'validated', 'blockers' => []] + $planned;
        }

        $canonicalId = (int) $plan['canonical_product_id_for_estimate'];
        $reviewRows = $this->keyById($data['reviews'] ?? []);
        $questionRows = $this->keyById($data['questions'] ?? []);
        $favoriteRows = $this->keyById($data['favorites'] ?? []);
        $deletedReviews = [];
        $deletedQuestions = [];
        $deletedFavorites = [];
        $result = $this->emptyGroupResult('reconciled');
        $result['answers_preserved'] = $planned['answers_preserved'];

        foreach ($plan['reviews']['variant_attribution_by_record'] ?? [] as $reviewId => $attribution) {
            if (($attribution['status'] ?? null) === 'attributable_to_one_variant'
                && ($attribution['method'] ?? null) !== 'existing_product_variant_id') {
                $result['reviews_attributed'] += $this->writeRepository->attributeReview(
                    (int) $reviewId,
                    (int) $attribution['variant_id'],
                );
            }
        }
        foreach ($plan['reviews']['actions'] ?? [] as $action) {
            if ($action['classification'] !== 'deduplicate_safe') {
                continue;
            }
            foreach ($this->deduplicationLosers($action['record_ids'], $reviewRows, $canonicalId) as $id) {
                $result['reviews_deduplicated'] += $this->writeRepository->softDeleteReview($id);
                $deletedReviews[$id] = true;
            }
        }
        foreach ($reviewRows as $id => $_) {
            if (! isset($deletedReviews[$id])) {
                $result['reviews_transferred'] += $this->writeRepository->transferReview($id, $canonicalId);
            }
        }

        foreach ($plan['questions']['actions'] ?? [] as $action) {
            if ($action['classification'] !== 'deduplicate_safe') {
                continue;
            }
            foreach ($this->deduplicationLosers($action['record_ids'], $questionRows, $canonicalId) as $id) {
                $result['questions_deduplicated'] += $this->writeRepository->deleteQuestion($id);
                $deletedQuestions[$id] = true;
            }
        }
        foreach ($questionRows as $id => $_) {
            if (! isset($deletedQuestions[$id])) {
                $result['questions_transferred'] += $this->writeRepository->transferQuestion($id, $canonicalId);
            }
        }

        foreach ($plan['favorites']['actions'] ?? [] as $action) {
            if ($action['classification'] !== 'deduplicate_safe') {
                continue;
            }
            foreach ($this->deduplicationLosers($action['record_ids'], $favoriteRows, $canonicalId) as $id) {
                $result['favorites_deduplicated'] += $this->writeRepository->deleteFavorite($id);
                $deletedFavorites[$id] = true;
            }
        }
        foreach ($favoriteRows as $id => $_) {
            if (! isset($deletedFavorites[$id])) {
                $result['favorites_transferred'] += $this->writeRepository->transferFavorite($id, $canonicalId);
            }
        }

        if (! $this->writeRepository->isAlreadyReconciled($plan)) {
            throw new RuntimeException('Engagement reconciliation postconditions failed.');
        }

        return $result;
    }

    /** @param array<string, mixed> $plan */
    private function assertApproved(string $identifier, array $plan): void
    {
        if (($plan['resulting_engagement_decision'] ?? null) === 'manual_review'
            || array_intersect($plan['blockers'] ?? [], [
                'manual_review_collision',
                'manual_question_collision',
                'reviews_user_product_unique_blocks_separate_variant_reviews',
                'product_questions_has_no_product_variant_id',
            ]) !== []) {
            throw new InvalidArgumentException('Unapproved engagement reconciliation group: '.$identifier);
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $data @return array<string, int> */
    private function plannedGroupResult(array $plan, array $data): array
    {
        $canonicalId = (int) $plan['canonical_product_id_for_estimate'];
        $questionRows = $this->keyById($data['questions'] ?? []);
        $deletedQuestionIds = [];
        foreach ($plan['questions']['actions'] ?? [] as $action) {
            if ($action['classification'] === 'deduplicate_safe') {
                $deletedQuestionIds = array_merge(
                    $deletedQuestionIds,
                    $this->deduplicationLosers($action['record_ids'], $questionRows, $canonicalId),
                );
            }
        }
        $answerCount = count(array_filter(
            $data['answers'] ?? [],
            static fn (array $answer): bool => ! in_array(
                (int) $answer['product_question_id'],
                $deletedQuestionIds,
                true,
            ),
        ));

        return [
            'reviews_attributed' => (int) ($plan['reviews']['variant_attribution_required'] ?? 0),
            'reviews_deduplicated' => (int) ($plan['reviews']['deduplicated'] ?? 0),
            'reviews_transferred' => (int) ($plan['reviews']['transferred'] ?? 0),
            'questions_deduplicated' => (int) ($plan['questions']['deduplicated'] ?? 0),
            'questions_transferred' => (int) ($plan['questions']['transferred'] ?? 0),
            'answers_preserved' => $answerCount,
            'favorites_deduplicated' => (int) ($plan['favorites']['deduplicated'] ?? 0),
            'favorites_transferred' => (int) ($plan['favorites']['transferred'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyGroupResult(string $status): array
    {
        return [
            'status' => $status,
            'reviews_attributed' => 0,
            'reviews_deduplicated' => 0,
            'reviews_transferred' => 0,
            'questions_deduplicated' => 0,
            'questions_transferred' => 0,
            'answers_preserved' => 0,
            'favorites_deduplicated' => 0,
            'favorites_transferred' => 0,
            'blockers' => [],
        ];
    }

    /** @param list<int> $recordIds @param array<int, array<string, mixed>> $rows @return list<int> */
    private function deduplicationLosers(array $recordIds, array $rows, int $canonicalId): array
    {
        $candidates = [];
        foreach ($recordIds as $id) {
            if (isset($rows[(int) $id])) {
                $candidates[] = $rows[(int) $id];
            }
        }
        usort($candidates, static fn (array $left, array $right): int => [
            (int) $left['product_id'] === $canonicalId ? 0 : 1,
            (string) ($left['created_at'] ?? ''),
            (int) $left['id'],
        ] <=> [
            (int) $right['product_id'] === $canonicalId ? 0 : 1,
            (string) ($right['created_at'] ?? ''),
            (int) $right['id'],
        ]);

        return array_map('intval', array_column(array_slice($candidates, 1), 'id'));
    }

    /** @return array<string, array<string, mixed>> */
    private function indexedGroups(string $path, string $label): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("The {$label} is missing or unreadable.");
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} contains invalid JSON.", previous: $exception);
        }
        if (! is_array($document) || ! is_array($document['groups'] ?? null)) {
            throw new RuntimeException("The {$label} does not contain a groups list.");
        }
        $groups = [];
        foreach ($document['groups'] as $group) {
            $identifier = is_array($group) ? ($group['group_identifier'] ?? null) : null;
            if (! is_string($identifier) || isset($groups[$identifier])) {
                throw new RuntimeException("The {$label} contains an invalid or duplicate group.");
            }
            $groups[$identifier] = $group;
        }
        ksort($groups, SORT_STRING);

        return $groups;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
