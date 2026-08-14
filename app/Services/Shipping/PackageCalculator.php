<?php

namespace App\Services\Shipping;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class PackageCalculator
{
    /**
     * @return array{
     *     weight: int,
     *     length: int,
     *     width: int,
     *     height: int,
     *     items: list<array{name: string, code: string, quantity: int, weight: int, length: int, width: int, height: int}>
     * }
     */
    public function calculate(Cart $cart): array
    {
        if ($cart->items->isEmpty()) {
            $this->fail('cart', 'Giỏ hàng đang trống!');
        }

        $length = $this->dimension('default_length_cm');
        $width = $this->dimension('default_width_cm');
        $height = $this->dimension('default_height_cm');
        $maxWeight = max(1, (int) config('shipping.package.max_weight_grams', 30_000));
        $totalWeight = 0;
        $items = [];

        foreach ($cart->items->sortBy('product_variant_id') as $item) {
            $quantity = $item->quantity;
            $variant = $item->productVariant;

            if (! is_int($quantity) || $quantity <= 0) {
                $this->fail('quantity', 'Số lượng sản phẩm trong giỏ hàng không hợp lệ!');
            }

            if ($variant === null || ! $variant->is_active || $variant->product === null || ! $variant->product->is_active) {
                $this->fail('cart', 'Giỏ hàng có sản phẩm không còn khả dụng!');
            }

            $unitWeight = $variant->weight;

            if (! is_int($unitWeight) || $unitWeight <= 0) {
                $this->fail('weight', "Sản phẩm {$variant->sku} chưa có khối lượng hợp lệ!");
            }

            if ($quantity > intdiv(PHP_INT_MAX, $unitWeight)) {
                $this->fail('weight', 'Tổng khối lượng kiện hàng vượt quá giới hạn cho phép!');
            }

            $lineWeight = $unitWeight * $quantity;

            if ($lineWeight > $maxWeight - $totalWeight) {
                $this->fail('weight', 'Tổng khối lượng kiện hàng vượt quá giới hạn cho phép!');
            }

            $totalWeight += $lineWeight;
            $items[] = [
                'name' => mb_substr($variant->product->name, 0, 255),
                'code' => mb_substr($variant->sku, 0, 50),
                'quantity' => $quantity,
                'weight' => $unitWeight,
                'length' => $length,
                'width' => $width,
                'height' => $height,
            ];
        }

        if ($totalWeight <= 0) {
            $this->fail('weight', 'Tổng khối lượng kiện hàng không hợp lệ!');
        }

        return [
            'weight' => $totalWeight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *     weight: int,
     *     length: int,
     *     width: int,
     *     height: int,
     *     items: list<array{name: string, quantity: int, price: int, weight: int, length: int, width: int, height: int}>
     * }
     */
    public function calculateForOrder(Order $order): array
    {
        if (! $order->relationLoaded('items') || $order->items->isEmpty()) {
            $this->fail('shipping', 'Đơn hàng không có sản phẩm để tạo vận đơn');
        }

        $length = $this->dimension('default_length_cm');
        $width = $this->dimension('default_width_cm');
        $height = $this->dimension('default_height_cm');
        $maxWeight = max(1, (int) config('shipping.package.max_weight_grams', 30_000));
        $totalWeight = 0;
        $items = [];

        foreach ($order->items->sortBy('product_variant_id') as $item) {
            $quantity = (int) $item->quantity;
            $variant = $item->productVariant;
            $unitWeight = $variant?->weight;

            if ($quantity <= 0) {
                $this->fail('shipping', 'Số lượng sản phẩm trong đơn hàng không hợp lệ');
            }

            if (! is_int($unitWeight) || $unitWeight <= 0) {
                $this->fail('shipping', "Sản phẩm {$item->sku} chưa có khối lượng hợp lệ");
            }

            if ($quantity > intdiv(PHP_INT_MAX, $unitWeight)) {
                $this->fail('shipping', 'Tổng khối lượng kiện hàng vượt quá giới hạn cho phép');
            }

            $lineWeight = $unitWeight * $quantity;

            if ($lineWeight > $maxWeight - $totalWeight) {
                $this->fail('shipping', 'Tổng khối lượng kiện hàng vượt quá giới hạn cho phép');
            }

            $totalWeight += $lineWeight;
            $items[] = [
                'name' => mb_substr((string) $item->product_name, 0, 255),
                'quantity' => $quantity,
                'price' => max(0, (int) $item->unit_price),
                'weight' => $unitWeight,
                'length' => $length,
                'width' => $width,
                'height' => $height,
            ];
        }

        return [
            'weight' => $totalWeight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'items' => $items,
        ];
    }

    private function dimension(string $key): int
    {
        $dimension = (int) config("shipping.package.{$key}");
        $maximum = max(1, (int) config('shipping.package.max_dimension_cm', 200));

        if ($dimension < 1 || $dimension > $maximum) {
            $this->fail('package', 'Cấu hình kích thước kiện hàng không hợp lệ!');
        }

        return $dimension;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
