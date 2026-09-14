<?php

namespace App\Services\Import;

use App\Repositories\Import\ProductVariantContentRepository;
use App\Support\Import\ProductVariantContentMovePlanner;
use App\Support\Import\ProductVariantContentMovePlanResult;
use JsonException;
use RuntimeException;

final class ProductVariantContentMovePlanningService
{
    public function __construct(
        private readonly ProductVariantContentRepository $repository,
        private readonly ProductVariantContentMovePlanner $planner,
    ) {}

    public function audit(string $contentReportPath): ProductVariantContentMovePlanResult
    {
        $groups = $this->eligibleGroups($contentReportPath);
        $plans = [];
        foreach ($groups as $group) {
            $plans[] = $this->planner->plan($group, $this->repository->groupData($group));
        }
        usort($plans, static fn (array $left, array $right): int => $left['group_identifier'] <=> $right['group_identifier']);
        $readiness = array_count_values(array_column($plans, 'readiness'));

        return new ProductVariantContentMovePlanResult([
            'groups_analyzed' => count($plans),
            'ready_for_apply' => (int) ($readiness['ready_for_variant_content_apply'] ?? 0),
            'needs_manual_rule' => (int) ($readiness['needs_manual_rule'] ?? 0),
            'blocked' => (int) ($readiness['blocked'] ?? 0),
            'total_variants_affected' => array_sum(array_column(array_column($plans, 'metrics'), 'variants_affected')),
            'total_variant_attribute_writes_proposed' => array_sum(array_column(array_column($plans, 'metrics'), 'variant_attribute_writes_proposed')),
            'total_image_rows_requiring_attribution' => array_sum(array_column(array_column($plans, 'metrics'), 'image_rows_requiring_attribution')),
            'duplicated_semantic_axis_cases' => $this->countItems($plans, 'duplicate_semantic_axes'),
            'equivalent_semantic_alias_cases' => $this->countItems($plans, 'equivalent_semantic_aliases'),
            'ambiguous_naming_cases' => count(array_filter($plans, static fn (array $plan): bool => in_array('canonical_name_requires_rule', $plan['unresolved_ambiguities'], true))),
            'ambiguous_image_cases' => count(array_filter($plans, static fn (array $plan): bool => ! $plan['t1_3_image_attribution_compatible'])),
            'specification_conflicts' => $this->countItems($plans, 'semantic_conflicts'),
            'stale_state_failures' => array_sum(array_map(static fn (array $plan): int => count($plan['stale_state_failures']), $plans)),
        ], $plans);
    }

    /** @return list<array<string, mixed>> */
    private function eligibleGroups(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The latest T1.5 content reconciliation report is missing or unreadable.');
        }
        try {
            $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The T1.5 content reconciliation report contains invalid JSON.', previous: $exception);
        }
        if (! is_array($report) || ! is_array($report['groups'] ?? null)) {
            throw new RuntimeException('The T1.5 content reconciliation report does not contain a groups list.');
        }

        $groups = [];
        foreach ($report['groups'] as $group) {
            if (! is_array($group) || ! is_string($group['group_identifier'] ?? null)) {
                throw new RuntimeException('The T1.5 content reconciliation report contains an invalid group.');
            }
            if (($group['recommended_merge_decision'] ?? null) === 'merge_after_variant_content_move') {
                $groups[] = $group;
            }
        }
        usort($groups, static fn (array $left, array $right): int => $left['group_identifier'] <=> $right['group_identifier']);

        return $groups;
    }

    /** @param list<array<string, mixed>> $plans */
    private function countItems(array $plans, string $key): int
    {
        return array_sum(array_map(static fn (array $plan): int => count($plan[$key]), $plans));
    }
}
