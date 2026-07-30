<?php

namespace App\Support\Import;

use Illuminate\Support\Str;

class ProductJsonMapper
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function map(array $record): array
    {
        $sourceId = $this->stringValue($record['productId'] ?? null);
        $name = $this->stringValue($record['name'] ?? null);
        $brandName = $this->normalizeText($record['brand'] ?? null);

        if ($sourceId === '') {
            return $this->quarantined($sourceId, 'missing_source_product_id');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $sourceId)) {
            return $this->quarantined($sourceId, 'invalid_source_product_id');
        }

        if ($name === '' || mb_strlen($name) > 255) {
            return $this->quarantined($sourceId, 'missing_name');
        }

        if ($brandName === '') {
            return $this->quarantined($sourceId, 'missing_brand');
        }

        $price = $this->positiveInteger($record['price'] ?? null);

        if ($price === null) {
            return $this->quarantined($sourceId, 'invalid_price');
        }

        $categoryPath = $this->canonicalCategoryPath($record);

        if ($categoryPath === []) {
            return $this->quarantined($sourceId, 'missing_category_mapping');
        }

        $warnings = ['missing_weight_policy'];
        $barcode = $this->barcode($record['specifications']['Barcode'] ?? null);

        if (
            array_key_exists('Barcode', is_array($record['specifications'] ?? null)
                ? $record['specifications']
                : [])
            && $barcode === null
        ) {
            $warnings[] = 'invalid_barcode';
        }

        $productSlug = 'hasaki-product-'.$sourceId;
        $sku = 'HS-'.$sourceId;
        $originalPrice = $this->positiveInteger($record['originalPrice'] ?? null);
        $variantPrice = $originalPrice !== null && $originalPrice > $price
            ? $originalPrice
            : $price;
        $salePrice = $variantPrice > $price ? $price : null;
        $attributes = $this->selectedAttributes($record['variants'] ?? null);
        $images = $this->images($record, $name, $warnings);
        $categories = $this->categoryNodes($categoryPath);
        $specifications = is_array($record['specifications'] ?? null)
            ? $record['specifications']
            : [];

        return [
            'status' => 'valid',
            'reason' => null,
            'warnings' => array_values(array_unique($warnings)),
            'source_id' => $sourceId,
            'product_slug' => $productSlug,
            'synthetic_sku' => $sku,
            'brand' => [
                'name' => $brandName,
                'slug' => Str::slug($brandName),
                'is_active' => true,
            ],
            'categories' => $categories,
            'category_slug' => $categories[array_key_last($categories)]['slug'],
            'product' => [
                'name' => $name,
                'slug' => $productSlug,
                'short_description' => $this->nullableString($record['subName'] ?? null),
                'description' => $this->nullableString($record['description'] ?? null),
                'ingredients' => $this->nullableString($record['ingredients'] ?? null),
                'usage_instructions' => $this->nullableString($record['usageInstructions'] ?? null),
                'origin_country' => $this->nullableString(
                    $specifications['Xuất xứ thương hiệu'] ?? null,
                ),
                'is_active' => true,
                'is_featured' => false,
            ],
            'variant' => [
                'name' => $attributes === [] ? $name : implode(' / ', array_values($attributes)),
                'sku' => $sku,
                'barcode' => $barcode,
                'attributes' => $attributes === [] ? null : $attributes,
                'price' => $variantPrice,
                'sale_price' => $salePrice,
                'weight' => null,
                'sort_order' => 0,
                'is_active' => true,
            ],
            'images' => $images,
            'metadata' => [
                'source_url' => $this->nullableString($record['url'] ?? null),
                'publication_code' => $this->nullableString($record['publicationCode'] ?? null),
                'specifications' => $specifications,
                'variant_options' => is_array($record['variants'] ?? null)
                    ? $record['variants']
                    : [],
                'category_paths' => is_array($record['categoryPaths'] ?? null)
                    ? $record['categoryPaths']
                    : [],
                'local_images' => is_array($record['localImages'] ?? null)
                    ? $record['localImages']
                    : [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function canonicalCategoryPath(array $record): array
    {
        $breadcrumb = $this->stringList($record['breadcrumbPath'] ?? null);

        if (count($breadcrumb) >= 3) {
            $path = array_slice($breadcrumb, 1, -1);

            if ($path !== []) {
                return $path;
            }
        }

        $categoryPaths = $record['categoryPaths'] ?? null;

        if (is_array($categoryPaths)) {
            foreach ($categoryPaths as $candidate) {
                $path = $this->stringList($candidate);

                if ($path !== []) {
                    return $path;
                }
            }
        }

        $category = $this->normalizeText($record['category'] ?? null);

        return $category === '' ? [] : [$category];
    }

    /**
     * @param  list<string>  $path
     * @return list<array{name: string, slug: string, parent_slug: string|null, sort_order: int, is_active: bool}>
     */
    private function categoryNodes(array $path): array
    {
        $nodes = [];
        $normalizedPath = [];
        $parentSlug = null;

        foreach ($path as $name) {
            $normalizedPath[] = mb_strtolower($this->normalizeText($name));
            $slug = sprintf(
                'hasaki-category-%s-%s',
                substr(hash('sha256', implode('>', $normalizedPath)), 0, 12),
                Str::slug($name),
            );

            $nodes[] = [
                'name' => $name,
                'slug' => $slug,
                'parent_slug' => $parentSlug,
                'sort_order' => 0,
                'is_active' => true,
            ];
            $parentSlug = $slug;
        }

        return $nodes;
    }

    /**
     * @return array<string, string>
     */
    private function selectedAttributes(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        $attributes = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $label = trim($this->normalizeText($group['label'] ?? null), " \t\n\r\0\x0B:");
            $selected = $this->normalizeText($group['selected'] ?? null);
            $key = Str::slug($label, '_');

            if ($key !== '' && $selected !== '') {
                $attributes[$key] = $selected;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $warnings
     * @return list<array{image_url: string, alt_text: string, sort_order: int, is_primary: bool}>
     */
    private function images(array $record, string $name, array &$warnings): array
    {
        $urls = is_array($record['images'] ?? null) ? $record['images'] : [];

        if ($urls === [] && $this->validUrl($record['image'] ?? null)) {
            $urls = [$record['image']];
        }

        $images = [];
        $seen = [];

        foreach ($urls as $url) {
            if (! $this->validUrl($url)) {
                $warnings[] = 'invalid_image_url';

                continue;
            }

            $url = (string) $url;

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $images[] = [
                'image_url' => $url,
                'alt_text' => $name,
                'sort_order' => count($images),
                'is_primary' => $images === [],
            ];
        }

        return $images;
    }

    private function barcode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{8,14}$/', $value) === 1 ? $value : null;
    }

    private function validUrl(mixed $value): bool
    {
        return is_string($value)
            && mb_strlen($value) <= 255
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => $this->normalizeText($item), $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function normalizeText(mixed $value): string
    {
        $value = $this->stringValue($value);

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value === '' ? null : $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (
            ! is_int($value)
            && ! (is_string($value) && preg_match('/^\d+$/', $value) === 1)
        ) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function quarantined(string $sourceId, string $reason): array
    {
        return [
            'status' => 'quarantined',
            'reason' => $reason,
            'warnings' => [],
            'source_id' => $sourceId,
        ];
    }
}
