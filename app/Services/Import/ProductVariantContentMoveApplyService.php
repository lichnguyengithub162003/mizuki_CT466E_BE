<?php

namespace App\Services\Import;

use App\Repositories\Import\ProductVariantContentMoveApplyRepository;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ProductVariantContentMoveApplyService
{
    public function __construct(private readonly ProductVariantContentMoveApplyRepository $repository) {}

    /** @param list<string> $requestedGroups @return array<string, mixed> */
    public function execute(string $planPath, array $requestedGroups, bool $apply = false): array
    {
        $plans = $this->indexedPlans($planPath);
        $requestedGroups = array_values(array_unique(array_filter(array_map('trim', $requestedGroups))));
        if ($apply && $requestedGroups === []) {
            throw new InvalidArgumentException('--apply requires at least one explicit --group option.');
        }
        if ($requestedGroups === []) {
            $requestedGroups = array_keys($plans);
        }
        sort($requestedGroups, SORT_STRING);
        $unknown = array_values(array_diff($requestedGroups, array_keys($plans)));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown or unapproved T1.9B group: '.implode(', ', $unknown));
        }

        $result = [
            'dry_run' => ! $apply,
            'groups_requested' => count($requestedGroups),
            'groups_validated' => 0,
            'groups_normalized' => 0,
            'already_normalized' => 0,
            'canonical_names_updated' => 0,
            'product_specification_keys_removed' => 0,
            'images_attributed' => 0,
            'variants_reparented' => 0,
            'order_items_transferred' => 0,
            'products_retired' => 0,
            'groups' => [],
        ];

        foreach ($requestedGroups as $identifier) {
            $plan = $plans[$identifier];
            $this->assertApprovedPlan($plan);
            $groupResult = $this->repository->transaction(
                fn (): array => $this->processGroup($plan, $apply),
            );
            $result['groups_validated']++;
            $result['groups_normalized'] += $groupResult['status'] === 'normalized' ? 1 : 0;
            $result['already_normalized'] += $groupResult['status'] === 'already_normalized' ? 1 : 0;
            foreach ([
                'canonical_names_updated', 'product_specification_keys_removed', 'images_attributed',
                'variants_reparented', 'order_items_transferred', 'products_retired',
            ] as $counter) {
                $result[$counter] += $groupResult[$counter];
            }
            $result['groups'][] = ['group_identifier' => $identifier] + $groupResult;
        }

        return $result;
    }

    /** @param array<string, mixed> $plan @return array<string, int|string> */
    private function processGroup(array $plan, bool $apply): array
    {
        $identifier = $this->requiredString($plan, 'group_identifier');
        $productIds = $this->integerList($plan, 'candidate_product_ids');
        $variantIds = $this->integerList($plan, 'candidate_variant_ids');
        $canonicalId = (int) ($plan['proposed_canonical_product_id'] ?? 0);
        if (count($productIds) < 2 || count($variantIds) !== count($productIds) || ! in_array($canonicalId, $productIds, true)) {
            throw new RuntimeException("T1.9B plan assumptions are incomplete for {$identifier}.");
        }

        $snapshot = $this->repository->lockSnapshot($productIds, $variantIds);
        $this->assertCompleteSet($identifier, $productIds, $variantIds, $snapshot);
        $this->assertIdentityAndCatalog($identifier, $plan, $snapshot);
        $desiredSpecifications = $this->desiredCanonicalSpecifications($plan, $snapshot, $canonicalId);
        $this->assertEngagementClean($identifier, $snapshot, $canonicalId);
        $this->assertVariantAttributes($identifier, $plan, $snapshot);

        if ($this->isAlreadyNormalized($identifier, $plan, $snapshot, $desiredSpecifications)) {
            return $this->emptyResult('already_normalized');
        }

        $this->assertCurrentNames($identifier, $plan, $snapshot);
        $this->assertMergeState($identifier, $plan, $snapshot);
        $this->assertImagesMatchPlan($identifier, $plan, $snapshot);
        $this->assertPrimaryInvariant($identifier, $plan);
        $planned = $this->plannedResult($plan, $snapshot, $desiredSpecifications);
        if (! $apply) {
            return ['status' => 'validated'] + $planned;
        }

        $attributesBefore = $this->variantAttributesFingerprint($snapshot, $variantIds);
        $canonicalName = $this->requiredString($plan, 'proposed_canonical_product_name');
        $content = $this->repository->updateCanonicalProduct($canonicalId, $canonicalName, $desiredSpecifications);
        $imagesAttributed = 0;
        foreach ($plan['image_attribution_plan'] as $imagePlan) {
            $imagesAttributed += $this->repository->attributeImage(
                (int) $imagePlan['image_id'],
                $canonicalId,
                (int) $imagePlan['intended_product_variant_id'],
            );
        }
        $variantsReparented = $this->repository->reparentVariants($variantIds, $canonicalId);
        $duplicateIds = array_values(array_diff($productIds, [$canonicalId]));
        $orderItemsTransferred = $this->repository->transferOrderItems($duplicateIds, $canonicalId);
        $productsRetired = $this->repository->retireProducts($duplicateIds, $identifier, $canonicalId);

        $after = $this->repository->lockSnapshot($productIds, $variantIds);
        if (! $this->isAlreadyNormalized($identifier, $plan, $after, $desiredSpecifications)
            || $attributesBefore !== $this->variantAttributesFingerprint($after, $variantIds)) {
            throw new RuntimeException("Postconditions or raw Variant attributes failed for {$identifier}.");
        }

        return [
            'status' => 'normalized',
            'canonical_names_updated' => $content['name_changed'],
            'product_specification_keys_removed' => $content['specification_keys_removed'],
            'images_attributed' => $imagesAttributed,
            'variants_reparented' => $variantsReparented,
            'order_items_transferred' => $orderItemsTransferred,
            'products_retired' => $productsRetired,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function assertApprovedPlan(array $plan): void
    {
        $identifier = $this->requiredString($plan, 'group_identifier');
        if (($plan['readiness'] ?? null) !== 'ready_for_variant_content_apply'
            || ($plan['reasons'] ?? null) !== []
            || ($plan['stale_state_failures'] ?? null) !== []
            || ($plan['t1_3_image_attribution_compatible'] ?? null) !== true
            || (int) ($plan['metrics']['variant_attribute_writes_proposed'] ?? -1) !== 0) {
            throw new InvalidArgumentException("T1.9B group {$identifier} is not approved for apply.");
        }
    }

    /** @param list<int> $productIds @param list<int> $variantIds @param array<string, mixed> $snapshot */
    private function assertCompleteSet(string $identifier, array $productIds, array $variantIds, array $snapshot): void
    {
        if (array_keys($snapshot['products']) !== $productIds || array_keys($snapshot['variants']) !== $variantIds) {
            throw new RuntimeException("Product or Variant set is stale for {$identifier}.");
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot */
    private function assertIdentityAndCatalog(string $identifier, array $plan, array $snapshot): void
    {
        $identities = [];
        $identityFingerprintParts = [];
        foreach ($plan['source_external_ids'] ?? [] as $identity) {
            $identities[(int) ($identity['product_id'] ?? 0)] = [
                'source' => $identity['source'] ?? null,
                'external_id' => isset($identity['external_id']) ? (string) $identity['external_id'] : null,
            ];
            $identityFingerprintParts[] = ($identity['source'] ?? '').':'.($identity['external_id'] ?? '');
        }
        if ('pvg-'.substr(hash('sha256', implode('|', $identityFingerprintParts)), 0, 16) !== $identifier) {
            throw new RuntimeException("Group identifier does not match its source identity fingerprint for {$identifier}.");
        }
        $brandValues = $plan['shared_product_fields']['brand_id']['values_by_product'] ?? [];
        $categoryValues = $plan['shared_product_fields']['category_id']['values_by_product'] ?? [];
        foreach ($snapshot['products'] as $productId => $product) {
            $identity = $identities[$productId] ?? null;
            if ($identity === null
                || $product['source'] !== $identity['source']
                || $product['external_id'] !== $identity['external_id']
                || (int) $product['brand_id'] !== (int) ($brandValues[$productId] ?? 0)
                || (int) $product['category_id'] !== (int) ($categoryValues[$productId] ?? 0)) {
                throw new RuntimeException("Source identity, brand, or category is stale for {$identifier}.");
            }
        }
        foreach ($plan['variant_attribute_changes'] ?? [] as $variantPlan) {
            $variant = $snapshot['variants'][(int) ($variantPlan['variant_id'] ?? 0)] ?? null;
            $sourceProduct = $snapshot['products'][(int) ($variantPlan['source_product_id'] ?? 0)] ?? null;
            if ($variant === null || $sourceProduct === null
                || $variant['source'] !== $sourceProduct['source']
                || $variant['external_id'] !== $sourceProduct['external_id']) {
                throw new RuntimeException("Authoritative Variant source identity is stale for {$identifier}.");
            }
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot */
    private function assertCurrentNames(string $identifier, array $plan, array $snapshot): void
    {
        foreach ($snapshot['products'] as $productId => $product) {
            if ($product['name'] !== ($plan['existing_product_names'][$productId] ?? null)) {
                throw new RuntimeException("Current Product names are stale for {$identifier}.");
            }
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot */
    private function assertVariantAttributes(string $identifier, array $plan, array $snapshot): void
    {
        foreach ($plan['variant_attribute_changes'] ?? [] as $variantPlan) {
            $variantId = (int) ($variantPlan['variant_id'] ?? 0);
            if (! isset($snapshot['variants'][$variantId])
                || $this->canonicalJson($snapshot['variants'][$variantId]['attributes'])
                    !== $this->canonicalJson($variantPlan['attributes_before'] ?? null)
                || $this->canonicalJson($variantPlan['attributes_before'] ?? null)
                    !== $this->canonicalJson($variantPlan['attributes_after'] ?? null)) {
                throw new RuntimeException("Raw Variant attributes or the zero-write plan are stale for {$identifier}.");
            }
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot */
    private function assertMergeState(string $identifier, array $plan, array $snapshot): void
    {
        $canonicalId = (int) $plan['proposed_canonical_product_id'];
        $bySourceProduct = $this->variantPlansBySourceProduct($plan);
        $initial = true;
        $baseNormalized = true;
        foreach ($bySourceProduct as $productId => $variantPlan) {
            $variant = $snapshot['variants'][(int) $variantPlan['variant_id']];
            $product = $snapshot['products'][$productId];
            $initial = $initial && $product['deleted_at'] === null && $variant['product_id'] === $productId;
            $baseNormalized = $baseNormalized && $variant['product_id'] === $canonicalId;
            if ($productId !== $canonicalId) {
                $marker = $product['retirement_marker'];
                $baseNormalized = $baseNormalized
                    && $product['deleted_at'] !== null
                    && ($marker['group_identifier'] ?? null) === $identifier
                    && ($marker['canonical_product_id'] ?? null) === $canonicalId
                    && ($marker['retired_product_id'] ?? null) === $productId;
            }
        }
        if (! $initial && ! $baseNormalized) {
            throw new RuntimeException("Product–Variant merge state is stale or partial for {$identifier}.");
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot */
    private function assertImagesMatchPlan(string $identifier, array $plan, array $snapshot): void
    {
        $imagePlans = [];
        foreach ($plan['image_attribution_plan'] ?? [] as $imagePlan) {
            $imagePlans[(int) ($imagePlan['image_id'] ?? 0)] = $imagePlan;
        }
        ksort($imagePlans, SORT_NUMERIC);
        if (array_keys($snapshot['images']) !== array_keys($imagePlans)) {
            throw new RuntimeException("ProductImage set is stale for {$identifier}.");
        }
        foreach ($snapshot['images'] as $imageId => $image) {
            $expected = $imagePlans[$imageId];
            if ($image['product_id'] !== (int) ($expected['current_product_id'] ?? 0)
                || $image['product_variant_id'] !== $this->nullableInt($expected['current_product_variant_id'] ?? null)
                || $image['is_primary'] !== (bool) ($expected['is_primary'] ?? false)
                || $image['sort_order'] !== (int) ($expected['sort_order'] ?? -1)) {
                throw new RuntimeException("ProductImage ownership or ordering is stale for {$identifier}.");
            }
        }
    }

    /** @param array<string, mixed> $plan */
    private function assertPrimaryInvariant(string $identifier, array $plan): void
    {
        $primaryCounts = [];
        foreach ($plan['image_attribution_plan'] ?? [] as $imagePlan) {
            if ((bool) ($imagePlan['is_primary'] ?? false)) {
                $variantId = (int) ($imagePlan['intended_product_variant_id'] ?? 0);
                $primaryCounts[$variantId] = ($primaryCounts[$variantId] ?? 0) + 1;
            }
        }
        if ($primaryCounts !== [] && max($primaryCounts) > 1) {
            throw new RuntimeException("Primary ProductImage invariant blocks {$identifier}.");
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function assertEngagementClean(string $identifier, array $snapshot, int $canonicalId): void
    {
        foreach ($snapshot['engagement'] as $table => $rows) {
            foreach ($rows as $row) {
                if ((int) $row['product_id'] !== $canonicalId) {
                    throw new RuntimeException("Active {$table} remains on a noncanonical Product for {$identifier}.");
                }
            }
        }
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot @param array<string, mixed> $desiredSpecifications */
    private function isAlreadyNormalized(string $identifier, array $plan, array $snapshot, array $desiredSpecifications): bool
    {
        $canonicalId = (int) $plan['proposed_canonical_product_id'];
        $canonical = $snapshot['products'][$canonicalId] ?? null;
        if ($canonical === null || $canonical['deleted_at'] !== null
            || $canonical['name'] !== ($plan['proposed_canonical_product_name'] ?? null)
            || $this->canonicalJson($canonical['specifications']) !== $this->canonicalJson($desiredSpecifications)) {
            return false;
        }
        foreach ($snapshot['variants'] as $variant) {
            if ($variant['deleted_at'] !== null || $variant['product_id'] !== $canonicalId) {
                return false;
            }
        }
        foreach ($snapshot['products'] as $productId => $product) {
            if ($productId === $canonicalId) {
                continue;
            }
            $marker = $product['retirement_marker'];
            if ($product['deleted_at'] === null
                || ($marker['group_identifier'] ?? null) !== $identifier
                || ($marker['canonical_product_id'] ?? null) !== $canonicalId
                || ($marker['retired_product_id'] ?? null) !== $productId) {
                return false;
            }
        }
        $plannedImages = [];
        foreach ($plan['image_attribution_plan'] ?? [] as $imagePlan) {
            $plannedImages[(int) $imagePlan['image_id']] = $imagePlan;
        }
        ksort($plannedImages, SORT_NUMERIC);
        if (array_keys($snapshot['images']) !== array_keys($plannedImages)) {
            return false;
        }
        foreach ($snapshot['images'] as $imageId => $image) {
            if ($image['product_id'] !== $canonicalId
                || $image['product_variant_id'] !== (int) $plannedImages[$imageId]['intended_product_variant_id']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function desiredCanonicalSpecifications(array $plan, array $snapshot, int $canonicalId): array
    {
        $specifications = $snapshot['products'][$canonicalId]['specifications'] ?? [];
        foreach (array_keys($plan['product_specification_keys_moving_to_variant'] ?? []) as $key) {
            unset($specifications[$key]);
        }

        return $specifications;
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $snapshot @param array<string, mixed> $desiredSpecifications @return array<string, int> */
    private function plannedResult(array $plan, array $snapshot, array $desiredSpecifications): array
    {
        $canonicalId = (int) $plan['proposed_canonical_product_id'];
        $duplicateIds = array_values(array_diff($this->integerList($plan, 'candidate_product_ids'), [$canonicalId]));
        $imageWrites = count(array_filter(
            $plan['image_attribution_plan'] ?? [],
            static fn (array $image): bool => (bool) ($image['write_required'] ?? false),
        ));

        return [
            'canonical_names_updated' => $snapshot['products'][$canonicalId]['name'] === $plan['proposed_canonical_product_name'] ? 0 : 1,
            'product_specification_keys_removed' => count(array_diff(
                array_keys($snapshot['products'][$canonicalId]['specifications']),
                array_keys($desiredSpecifications),
            )),
            'images_attributed' => $imageWrites,
            'variants_reparented' => count(array_filter(
                $snapshot['variants'],
                static fn (array $variant): bool => $variant['product_id'] !== $canonicalId,
            )),
            'order_items_transferred' => count(array_filter(
                $snapshot['order_items'],
                static fn (array $item): bool => in_array((int) $item['product_id'], $duplicateIds, true),
            )),
            'products_retired' => count(array_filter(
                $duplicateIds,
                static fn (int $id): bool => $snapshot['products'][$id]['deleted_at'] === null,
            )),
        ];
    }

    /** @param array<string, mixed> $plan @return array<int, array<string, mixed>> */
    private function variantPlansBySourceProduct(array $plan): array
    {
        $result = [];
        foreach ($plan['variant_attribute_changes'] ?? [] as $variantPlan) {
            $result[(int) $variantPlan['source_product_id']] = $variantPlan;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /** @param array<string, mixed> $snapshot @param list<int> $variantIds */
    private function variantAttributesFingerprint(array $snapshot, array $variantIds): string
    {
        $attributes = [];
        foreach ($variantIds as $variantId) {
            $attributes[$variantId] = $snapshot['variants'][$variantId]['attributes'];
        }

        return hash('sha256', $this->canonicalJson($attributes));
    }

    /** @return array<string, int|string> */
    private function emptyResult(string $status): array
    {
        return [
            'status' => $status,
            'canonical_names_updated' => 0,
            'product_specification_keys_removed' => 0,
            'images_attributed' => 0,
            'variants_reparented' => 0,
            'order_items_transferred' => 0,
            'products_retired' => 0,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function indexedPlans(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The latest T1.9B plan is missing or unreadable.');
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The T1.9B plan contains invalid JSON.', previous: $exception);
        }
        if (! is_array($document) || ! is_array($document['groups'] ?? null)) {
            throw new RuntimeException('The T1.9B plan does not contain a groups list.');
        }
        $plans = [];
        foreach ($document['groups'] as $plan) {
            $identifier = is_array($plan) ? ($plan['group_identifier'] ?? null) : null;
            if (! is_string($identifier) || $identifier === '' || isset($plans[$identifier])) {
                throw new RuntimeException('The T1.9B plan contains an invalid or duplicate group.');
            }
            $plans[$identifier] = $plan;
        }
        ksort($plans, SORT_STRING);

        return $plans;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

    /** @param array<string, mixed> $values @return list<int> */
    private function integerList(array $values, string $key): array
    {
        $items = $values[$key] ?? null;
        if (! is_array($items) || $items === []) {
            throw new RuntimeException("T1.9B plan has invalid {$key}.");
        }
        $items = array_values(array_unique(array_map('intval', $items)));
        sort($items, SORT_NUMERIC);

        return $items;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("T1.9B plan is missing {$key}.");
        }

        return $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
