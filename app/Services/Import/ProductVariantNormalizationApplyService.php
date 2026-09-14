<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\Import\ProductVariantNormalizationRetirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class ProductVariantNormalizationApplyService
{
    public function __construct(
        private readonly ProductVariantNormalizationRetirement $retirement,
    ) {}

    /**
     * @param  list<string>  $groupIdentifiers
     * @return array<string, int|bool|list<array<string, int|string>>>
     */
    public function execute(string $manifestPath, array $groupIdentifiers, bool $apply = false): array
    {
        $groupIdentifiers = array_values(array_unique(array_map('trim', $groupIdentifiers)));
        if ($apply && $groupIdentifiers === []) {
            throw new InvalidArgumentException('--apply requires at least one explicit --group option.');
        }
        if ($groupIdentifiers === []) {
            $groupIdentifiers = array_keys($this->approvedGroups());
        }

        $approvedGroups = $this->approvedGroups();
        $unapproved = array_values(array_diff($groupIdentifiers, array_keys($approvedGroups)));
        if ($unapproved !== []) {
            throw new InvalidArgumentException('Unapproved normalization group: '.implode(', ', $unapproved));
        }

        $manifestGroups = $this->manifestGroups($manifestPath);
        $result = [
            'dry_run' => ! $apply,
            'groups_requested' => count($groupIdentifiers),
            'groups_validated' => 0,
            'groups_normalized' => 0,
            'groups_already_normalized' => 0,
            'variants_reparented' => 0,
            'images_attributed' => 0,
            'order_items_transferred' => 0,
            'products_retired' => 0,
            'groups' => [],
        ];

        foreach ($groupIdentifiers as $identifier) {
            $group = $manifestGroups[$identifier] ?? null;
            if (! is_array($group)) {
                throw new RuntimeException("Approved group {$identifier} is absent from the supplied manifest.");
            }
            $this->assertApprovedManifestAssumptions($group, $approvedGroups[$identifier]);

            $groupResult = DB::transaction(
                fn (): array => $this->processGroup($group, $apply),
                attempts: 1,
            );
            $result['groups_validated']++;
            $result['groups_normalized'] += $groupResult['normalized'];
            $result['groups_already_normalized'] += $groupResult['already_normalized'];
            $result['variants_reparented'] += $groupResult['variants_reparented'];
            $result['images_attributed'] += $groupResult['images_attributed'];
            $result['order_items_transferred'] += $groupResult['order_items_transferred'];
            $result['products_retired'] += $groupResult['products_retired'];
            $result['groups'][] = ['group_identifier' => $identifier, 'status' => $groupResult['status']];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function approvedGroups(): array
    {
        $approved = config('product_variant_normalization.approved_groups');
        if (! is_array($approved) || $approved === []) {
            throw new RuntimeException('No Product–Variant normalization groups are approved.');
        }

        return $approved;
    }

    /** @param array<string, mixed> $group @param array<string, mixed> $approved */
    private function assertApprovedManifestAssumptions(array $group, array $approved): void
    {
        $actual = [
            'product_ids' => $this->integerList($group, 'candidate_product_ids'),
            'variant_ids' => $this->integerList($group, 'candidate_variant_ids'),
            'canonical_product_id' => (int) ($group['recommended_canonical_product_id'] ?? 0),
            'source_external_ids' => $group['source_external_ids'] ?? null,
            'brand_id' => (int) ($group['brand']['id'] ?? 0),
            'category_id' => (int) ($group['category']['id'] ?? 0),
        ];
        if (($group['classification'] ?? null) !== 'auto_safe'
            || ($group['failed_hard_gates'] ?? []) !== []
            || $actual !== $approved) {
            throw new RuntimeException('Manifest content no longer matches the explicitly approved assumptions for '.$this->requiredString($group, 'group_identifier').'.');
        }

        $identity = implode('|', array_map(
            static fn (array $item): string => ($item['source'] ?? '').':'.($item['external_id'] ?? ''),
            $actual['source_external_ids'],
        ));
        $computedIdentifier = 'pvg-'.substr(hash('sha256', $identity), 0, 16);
        if ($computedIdentifier !== $group['group_identifier']) {
            throw new RuntimeException('Manifest group identifier does not match its T1.1 source identity fingerprint.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function manifestGroups(string $manifestPath): array
    {
        $path = $this->absolutePath($manifestPath);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Normalization manifest is missing or unreadable.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Normalization manifest contains invalid JSON.', previous: $exception);
        }
        if (! is_array($manifest) || ! is_array($manifest['groups'] ?? null)) {
            throw new RuntimeException('Normalization manifest does not contain a groups list.');
        }

        $groups = [];
        foreach ($manifest['groups'] as $group) {
            $identifier = is_array($group) ? ($group['group_identifier'] ?? null) : null;
            if (is_string($identifier)) {
                $groups[$identifier] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{status: string, normalized: int, already_normalized: int, variants_reparented: int, images_attributed: int, order_items_transferred: int, products_retired: int}
     */
    private function processGroup(array $group, bool $apply): array
    {
        $identifier = $this->requiredString($group, 'group_identifier');
        $productIds = $this->integerList($group, 'candidate_product_ids');
        $variantIds = $this->integerList($group, 'candidate_variant_ids');
        $canonicalId = (int) ($group['recommended_canonical_product_id'] ?? 0);
        if (count($productIds) < 2 || $variantIds === [] || ! in_array($canonicalId, $productIds, true)) {
            throw new RuntimeException("Manifest assumptions are incomplete for {$identifier}.");
        }

        $products = Product::query()->withTrashed()->whereIn('id', $productIds)
            ->lockForUpdate()->get()->keyBy('id');
        $variants = ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)
            ->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== count($productIds) || $variants->count() !== count($variantIds)) {
            throw new RuntimeException("Current product or variant IDs no longer match manifest group {$identifier}.");
        }

        $this->assertProductIdentity($group, $products);
        $this->assertVariantSet($identifier, $productIds, $variantIds);
        $variantByOriginalProduct = $this->variantBySourceIdentity($identifier, $products, $variants);
        $this->assertNoBlockingProductReferences($identifier, $productIds);

        $state = $this->state($identifier, $canonicalId, $products, $variants, $variantByOriginalProduct);
        $this->assertImagesSafe($identifier, $canonicalId, $products, $variantByOriginalProduct, $state);

        if ($state === 'normalized') {
            return $this->groupResult('already_normalized', alreadyNormalized: 1);
        }
        if (! $apply) {
            return $this->groupResult('validated');
        }

        $imagesAttributed = 0;
        foreach ($productIds as $productId) {
            $variant = $variantByOriginalProduct[$productId];
            $images = ProductImage::query()->where('product_id', $productId)->lockForUpdate()->get();

            foreach ($images as $image) {
                $image->product_id = $canonicalId;
                $image->product_variant_id ??= $variant->id;
                if ($image->isDirty()) {
                    $image->save();
                    $imagesAttributed++;
                }
            }
        }

        $variantsReparented = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->where('product_id', '<>', $canonicalId)
            ->update(['product_id' => $canonicalId]);
        $duplicateIds = array_values(array_diff($productIds, [$canonicalId]));
        $orderItemsTransferred = DB::table('order_items')
            ->whereIn('product_id', $duplicateIds)
            ->update(['product_id' => $canonicalId]);

        $productsRetired = 0;
        foreach ($duplicateIds as $duplicateId) {
            /** @var Product $duplicate */
            $duplicate = $products->get($duplicateId);
            $duplicate->source_variant_groups = $this->retirement->mark($duplicate, $identifier, $canonicalId);
            $duplicate->is_active = false;
            $duplicate->save();
            $duplicate->delete();
            $productsRetired++;
        }

        $this->assertPostconditions($identifier, $canonicalId, $duplicateIds, $variantIds);

        return $this->groupResult(
            'normalized',
            normalized: 1,
            variantsReparented: $variantsReparented,
            imagesAttributed: $imagesAttributed,
            orderItemsTransferred: $orderItemsTransferred,
            productsRetired: $productsRetired,
        );
    }

    /** @param Collection<int, Product> $products */
    private function assertProductIdentity(array $group, Collection $products): void
    {
        $productIds = $this->integerList($group, 'candidate_product_ids');
        $identities = $group['source_external_ids'] ?? null;
        $brandId = (int) ($group['brand']['id'] ?? 0);
        $categoryId = (int) ($group['category']['id'] ?? 0);
        if (! is_array($identities) || count($identities) !== count($productIds) || $brandId < 1 || $categoryId < 1) {
            throw new RuntimeException('Manifest product identity assumptions are incomplete.');
        }

        foreach ($productIds as $index => $productId) {
            /** @var Product $product */
            $product = $products->get($productId);
            $identity = $identities[$index] ?? [];
            if ($product->source !== ($identity['source'] ?? null)
                || $product->external_id !== (string) ($identity['external_id'] ?? '')
                || (int) $product->brand_id !== $brandId
                || (int) $product->category_id !== $categoryId) {
                throw new RuntimeException("Current identity, brand, or category differs from the manifest for product {$productId}.");
            }
        }
    }

    /** @param list<int> $productIds @param list<int> $variantIds */
    private function assertVariantSet(string $identifier, array $productIds, array $variantIds): void
    {
        $current = ProductVariant::query()->withTrashed()->whereIn('product_id', $productIds)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $expected = $variantIds;
        sort($expected, SORT_NUMERIC);
        if ($current !== $expected) {
            throw new RuntimeException("Current variant set differs from manifest group {$identifier}.");
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, ProductVariant>
     */
    private function variantBySourceIdentity(string $identifier, Collection $products, Collection $variants): array
    {
        $mapped = [];
        foreach ($products as $product) {
            $matches = $variants->filter(fn (ProductVariant $variant): bool => ! $variant->trashed()
                && $variant->source === $product->source
                && $variant->external_id === $product->external_id);
            if ($matches->count() !== 1) {
                throw new RuntimeException("Variant source identity no longer maps uniquely in {$identifier}.");
            }
            $mapped[(int) $product->id] = $matches->first();
        }

        return $mapped;
    }

    /** @param list<int> $productIds */
    private function assertNoBlockingProductReferences(string $identifier, array $productIds): void
    {
        foreach (['reviews', 'product_questions', 'product_favorites'] as $table) {
            if (DB::table($table)->whereIn('product_id', $productIds)->lockForUpdate()->exists()) {
                throw new RuntimeException("Product-owned {$table} references block normalization group {$identifier}.");
            }
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, ProductVariant>  $variantByOriginalProduct
     */
    private function state(
        string $identifier,
        int $canonicalId,
        Collection $products,
        Collection $variants,
        array $variantByOriginalProduct,
    ): string {
        $initial = $products->every(fn (Product $product): bool => ! $product->trashed()
            && $variantByOriginalProduct[(int) $product->id]->product_id === $product->id);
        if ($initial) {
            return 'initial';
        }

        $normalized = $variants->every(fn (ProductVariant $variant): bool => (int) $variant->product_id === $canonicalId)
            && ! $products->get($canonicalId)->trashed();
        foreach ($products as $product) {
            if ((int) $product->id === $canonicalId) {
                continue;
            }
            $marker = $this->retirement->marker($product);
            $normalized = $normalized
                && $product->trashed()
                && $marker !== null
                && $marker['group_identifier'] === $identifier
                && $marker['canonical_product_id'] === $canonicalId
                && $marker['retired_product_id'] === (int) $product->id;
        }
        if ($normalized) {
            return 'normalized';
        }

        throw new RuntimeException("Current state is stale or partially normalized for group {$identifier}.");
    }

    /** @param Collection<int, Product> $products @param array<int, ProductVariant> $variantByOriginalProduct */
    private function assertImagesSafe(
        string $identifier,
        int $canonicalId,
        Collection $products,
        array $variantByOriginalProduct,
        string $state,
    ): void {
        foreach ($products as $product) {
            $variant = $variantByOriginalProduct[(int) $product->id];
            $images = $state === 'initial'
                ? ProductImage::query()->where('product_id', $product->id)->lockForUpdate()->get()
                : ProductImage::query()->where('product_id', $canonicalId)
                    ->where('product_variant_id', $variant->id)->lockForUpdate()->get();
            if ($images->where('is_primary', true)->count() > 1
                || $images->contains(fn (ProductImage $image): bool => $image->product_variant_id !== null
                    && (int) $image->product_variant_id !== (int) $variant->id)) {
                throw new RuntimeException("Image ownership or primary-image conflict blocks group {$identifier}.");
            }
        }
    }

    /** @param list<int> $duplicateIds @param list<int> $variantIds */
    private function assertPostconditions(string $identifier, int $canonicalId, array $duplicateIds, array $variantIds): void
    {
        if (ProductVariant::query()->whereIn('id', $variantIds)->where('product_id', '<>', $canonicalId)->exists()
            || ProductImage::query()->whereIn('product_id', $duplicateIds)->exists()
            || DB::table('order_items')->whereIn('product_id', $duplicateIds)->exists()) {
            throw new RuntimeException("Postconditions failed for normalization group {$identifier}.");
        }
    }

    /** @return array{status: string, normalized: int, already_normalized: int, variants_reparented: int, images_attributed: int, order_items_transferred: int, products_retired: int} */
    private function groupResult(
        string $status,
        int $normalized = 0,
        int $alreadyNormalized = 0,
        int $variantsReparented = 0,
        int $imagesAttributed = 0,
        int $orderItemsTransferred = 0,
        int $productsRetired = 0,
    ): array {
        return [
            'status' => $status,
            'normalized' => $normalized,
            'already_normalized' => $alreadyNormalized,
            'variants_reparented' => $variantsReparented,
            'images_attributed' => $imagesAttributed,
            'order_items_transferred' => $orderItemsTransferred,
            'products_retired' => $productsRetired,
        ];
    }

    private function absolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return preg_match('~^(?:[A-Za-z]:/|/)~', $normalized) === 1 ? $path : base_path($path);
    }

    /** @param array<string, mixed> $group */
    private function requiredString(array $group, string $key): string
    {
        $value = $group[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Manifest group is missing {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $group @return list<int> */
    private function integerList(array $group, string $key): array
    {
        $values = $group[$key] ?? null;
        if (! is_array($values) || $values === [] || collect($values)->contains(fn (mixed $id): bool => ! is_numeric($id) || (int) $id < 1)) {
            throw new RuntimeException("Manifest group has invalid {$key}.");
        }

        return array_values(array_unique(array_map('intval', $values)));
    }
}
