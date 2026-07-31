<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Shipping\PackageCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set([
        'shipping.package.default_length_cm' => 20,
        'shipping.package.default_width_cm' => 15,
        'shipping.package.default_height_cm' => 10,
        'shipping.package.max_dimension_cm' => 200,
        'shipping.package.max_weight_grams' => 30_000,
    ]);
});

function packageTestCart(array $rows): Cart
{
    $items = collect($rows)->map(function (array $row, int $index): CartItem {
        $product = new Product([
            'name' => $row['name'] ?? "Product {$index}",
            'is_active' => $row['product_active'] ?? true,
        ]);
        $product->id = $index + 100;
        $variant = new ProductVariant([
            'name' => 'Default',
            'sku' => $row['sku'] ?? "SKU-{$index}",
            'weight' => array_key_exists('weight', $row) ? $row['weight'] : 100,
            'price' => 100_000,
            'is_active' => $row['variant_active'] ?? true,
        ]);
        $variant->id = $row['variant_id'] ?? $index + 1;
        $variant->product_id = $product->id;
        $variant->setRelation('product', $product);
        $item = new CartItem([
            'product_variant_id' => $variant->id,
            'quantity' => $row['quantity'] ?? 1,
        ]);
        $item->id = $index + 1;
        $item->setRelation('productVariant', $variant);

        return $item;
    });
    $cart = new Cart;
    $cart->setRelation('items', new Collection($items->all()));

    return $cart;
}

test('derives deterministic package weight from variant weight and quantities', function (): void {
    $cart = packageTestCart([
        ['variant_id' => 2, 'weight' => 250, 'quantity' => 3],
        ['variant_id' => 1, 'weight' => 100, 'quantity' => 2],
    ]);
    $calculator = app(PackageCalculator::class);

    $first = $calculator->calculate($cart);
    $second = $calculator->calculate($cart);

    expect($first)->toBe($second)
        ->and($first['weight'])->toBe(950)
        ->and(array_column($first['items'], 'weight'))->toBe([100, 250])
        ->and($first['length'])->toBe(20)
        ->and($first['width'])->toBe(15)
        ->and($first['height'])->toBe(10);
});

test('rejects missing non-positive and unavailable variant weights', function (array $row, string $field): void {
    $cart = packageTestCart([$row]);

    try {
        app(PackageCalculator::class)->calculate($cart);
        $this->fail('Expected package validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'missing weight' => [['weight' => null], 'weight'],
    'zero weight' => [['weight' => 0], 'weight'],
    'inactive variant' => [['variant_active' => false], 'cart'],
    'inactive product' => [['product_active' => false], 'cart'],
]);

test('rejects empty carts invalid quantities and weight overflow', function (): void {
    $calculator = app(PackageCalculator::class);

    expect(fn (): array => $calculator->calculate(packageTestCart([])))
        ->toThrow(ValidationException::class)
        ->and(fn (): array => $calculator->calculate(packageTestCart([
            ['weight' => 100, 'quantity' => 0],
        ])))->toThrow(ValidationException::class)
        ->and(fn (): array => $calculator->calculate(packageTestCart([
            ['weight' => 20_000, 'quantity' => 2],
        ])))->toThrow(ValidationException::class);
});

test('rejects package dimensions outside centralized constraints', function (): void {
    config()->set('shipping.package.default_length_cm', 201);

    expect(fn (): array => app(PackageCalculator::class)->calculate(packageTestCart([
        ['weight' => 100, 'quantity' => 1],
    ])))->toThrow(ValidationException::class);
});
