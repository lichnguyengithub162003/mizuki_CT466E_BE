<?php

namespace App\Services\Shipping;

use App\Exceptions\Shipping\GhnApiException;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\User;
use App\Models\UserAddress;
use App\Repositories\UserAddressRepository;
use App\Services\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;

class ShippingQuoteService
{
    private const CACHE_PREFIX = 'shipping.quote.';

    public function __construct(
        private readonly CartService $carts,
        private readonly UserAddressRepository $addresses,
        private readonly PackageCalculator $packages,
        private readonly GhnServiceSelector $services,
        private readonly GhnClient $ghn,
    ) {}

    /** @return array<string, mixed> */
    public function quote(User $user, int $addressId): array
    {
        $cart = $this->carts->getForUser($user);
        $address = $this->addresses->findForUser($user->id, $addressId);
        $branch = $this->validatedBranch($cart);
        $this->validateAddressMapping($address);
        $package = $this->packages->calculate($cart);
        $shopId = $this->configuredShopId();

        try {
            $service = $this->services->select($this->ghn->availableServices(
                shopId: $shopId,
                fromDistrictId: $branch->ghn_district_id,
                toDistrictId: $address->ghn_district_id,
            ));
            $fee = $this->ghn->calculateShippingFee($this->feePayload(
                cart: $cart,
                branch: $branch,
                address: $address,
                package: $package,
                service: $service,
            ));
            $expectedDeliveryTime = $fee['expected_delivery_time'] ?? null;

            if ($expectedDeliveryTime === null || $expectedDeliveryTime === '') {
                try {
                    $expectedDeliveryTime = $this->ghn->calculateExpectedDeliveryTime([
                        'from_district_id' => $branch->ghn_district_id,
                        'from_ward_code' => $branch->ghn_ward_code,
                        'to_district_id' => $address->ghn_district_id,
                        'to_ward_code' => $address->ghn_ward_code,
                        'service_id' => $service['service_id'],
                    ]);
                } catch (GhnApiException $exception) {
                    Log::warning('GHN leadtime unavailable for shipping quote', [
                        'operation' => $exception->operation,
                        'provider_code' => $exception->providerCode,
                        'branch_id' => $branch->id,
                        'address_id' => $address->id,
                        'service_id' => $service['service_id'],
                    ]);
                    $expectedDeliveryTime = null;
                }
            }
        } catch (GhnApiException) {
            throw ValidationException::withMessages([
                'shipping' => ['Không thể lấy phí vận chuyển từ GHN lúc này. Vui lòng thử lại!'],
            ]);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = CarbonImmutable::now()->addMinutes($this->ttlMinutes());
        $quote = [
            'user_id' => $user->id,
            'cart_id' => $cart->id,
            'branch_id' => $branch->id,
            'address_id' => $address->id,
            'fingerprint' => $this->fingerprint($user, $cart, $address, $package),
            'package' => $package,
            'service_id' => $service['service_id'],
            'service_type_id' => $service['service_type_id'],
            'shipping_fee' => $fee['total'],
            'fee_breakdown' => $this->feeBreakdown($fee),
            'expected_delivery_time' => $expectedDeliveryTime,
            'expires_at' => $expiresAt->toISOString(),
        ];

        Cache::put($this->cacheKey($token), $quote, $expiresAt);

        return $quote + ['quote_token' => $token];
    }

    /** @return array<string, mixed> */
    public function loadForCheckout(
        User $user,
        int $addressId,
        string $token,
    ): array {
        $quote = Cache::get($this->cacheKey($token));

        if (! is_array($quote)
            || ($quote['user_id'] ?? null) !== $user->id
            || ($quote['address_id'] ?? null) !== $addressId) {
            $this->invalidQuote();
        }

        $cart = $this->carts->getForUser($user);
        $address = $this->addresses->findForUser($user->id, $addressId);
        $this->assertMatches($quote, $user, $cart, $address);

        return $quote;
    }

    /** @param array<string, mixed> $quote */
    public function assertMatches(
        array $quote,
        User $user,
        Cart $cart,
        UserAddress $address,
    ): void {
        $expiresAt = $quote['expires_at'] ?? null;

        if (! is_string($expiresAt)) {
            $this->invalidQuote();
        }

        try {
            if (CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(CarbonImmutable::now())) {
                $this->invalidQuote();
            }
        } catch (\Throwable) {
            $this->invalidQuote();
        }

        $branch = $this->validatedBranch($cart);
        $this->validateAddressMapping($address);
        $package = $this->packages->calculate($cart);
        $fingerprint = $this->fingerprint($user, $cart, $address, $package);

        if (($quote['user_id'] ?? null) !== $user->id
            || ($quote['cart_id'] ?? null) !== $cart->id
            || ($quote['branch_id'] ?? null) !== $branch->id
            || ($quote['address_id'] ?? null) !== $address->id
            || ! is_string($quote['fingerprint'] ?? null)
            || ! hash_equals($quote['fingerprint'], $fingerprint)
            || ! is_int($quote['shipping_fee'] ?? null)
            || $quote['shipping_fee'] < 0) {
            $this->invalidQuote();
        }
    }

    public function consume(string $token): void
    {
        Cache::forget($this->cacheKey($token));
    }

    /**
     * @param  array{weight: int, length: int, width: int, height: int, items: list<array<string, mixed>>}  $package
     */
    public function fingerprint(
        User $user,
        Cart $cart,
        UserAddress $address,
        array $package,
    ): string {
        $items = $cart->items->sortBy('product_variant_id')->map(function ($item): array {
            $variant = $item->productVariant;

            return [
                'cart_item_id' => $item->id,
                'variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'item_updated_at' => $item->updated_at?->toISOString(),
                'weight' => $variant?->weight,
                'price' => $variant?->price,
                'sale_price' => $variant?->sale_price,
                'variant_active' => $variant?->is_active,
                'variant_updated_at' => $variant?->updated_at?->toISOString(),
                'product_id' => $variant?->product_id,
                'product_active' => $variant?->product?->is_active,
                'product_updated_at' => $variant?->product?->updated_at?->toISOString(),
            ];
        })->values()->all();
        $boundData = [
            'user_id' => $user->id,
            'cart_id' => $cart->id,
            'branch' => [
                'id' => $cart->branch_id,
                'ghn_district_id' => $cart->branch?->ghn_district_id,
                'ghn_ward_code' => $cart->branch?->ghn_ward_code,
            ],
            'address' => [
                'id' => $address->id,
                'recipient_name' => $address->recipient_name,
                'recipient_phone' => $address->recipient_phone,
                'province' => $address->province,
                'district' => $address->district,
                'ward' => $address->ward,
                'hamlet' => $address->hamlet,
                'address_line' => $address->address_line,
                'ghn_province_id' => $address->ghn_province_id,
                'ghn_district_id' => $address->ghn_district_id,
                'ghn_ward_code' => $address->ghn_ward_code,
                'updated_at' => $address->updated_at?->toISOString(),
            ],
            'items' => $items,
            'package' => $package,
        ];

        try {
            return hash('sha256', json_encode(
                $boundData,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ));
        } catch (JsonException) {
            $this->invalidQuote();
        }
    }

    private function validatedBranch(Cart $cart): Branch
    {
        $branch = $cart->branch;

        if ($cart->branch_id === null || $branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => ['Vui lòng chọn chi nhánh trước khi tính phí vận chuyển!'],
            ]);
        }

        if (! $branch->is_active
            || $branch->ghn_district_id <= 0
            || trim((string) $branch->ghn_ward_code) === '') {
            throw ValidationException::withMessages([
                'branch_id' => ['Chi nhánh chưa có thông tin GHN hợp lệ!'],
            ]);
        }

        return $branch;
    }

    private function validateAddressMapping(UserAddress $address): void
    {
        if ($address->ghn_district_id === null
            || $address->ghn_district_id <= 0
            || trim((string) $address->ghn_ward_code) === '') {
            throw ValidationException::withMessages([
                'address_id' => ['Địa chỉ chưa được ánh xạ đầy đủ với GHN!'],
            ]);
        }
    }

    /**
     * @param  array{weight: int, length: int, width: int, height: int, items: list<array<string, mixed>>}  $package
     * @param  array{service_id: int, short_name: string, service_type_id: int}  $service
     * @return array<string, mixed>
     */
    private function feePayload(
        Cart $cart,
        Branch $branch,
        UserAddress $address,
        array $package,
        array $service,
    ): array {
        return [
            'service_id' => $service['service_id'],
            'from_district_id' => $branch->ghn_district_id,
            'from_ward_code' => $branch->ghn_ward_code,
            'to_district_id' => $address->ghn_district_id,
            'to_ward_code' => $address->ghn_ward_code,
            'length' => $package['length'],
            'width' => $package['width'],
            'height' => $package['height'],
            'weight' => $package['weight'],
            'insurance_value' => min(
                max(0, (int) $cart->total_after_discount),
                max(0, (int) config('shipping.package.max_insurance_value', 5_000_000)),
            ),
            'coupon' => null,
            'items' => $package['items'],
        ];
    }

    /** @param array<string, int|string|null> $fee */
    private function feeBreakdown(array $fee): array
    {
        return collect($fee)
            ->except(['total', 'expected_delivery_time'])
            ->filter(static fn (mixed $value): bool => is_int($value))
            ->all();
    }

    private function configuredShopId(): int
    {
        $shopId = config('services.ghn.shop_id');

        if (! (is_int($shopId) || is_string($shopId) && ctype_digit($shopId))
            || (int) $shopId <= 0) {
            throw ValidationException::withMessages([
                'shipping' => ['Cấu hình cửa hàng GHN chưa hợp lệ!'],
            ]);
        }

        return (int) $shopId;
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('shipping.quote_ttl_minutes', 10));
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX.hash('sha256', $token);
    }

    private function invalidQuote(): never
    {
        throw ValidationException::withMessages([
            'shipping_quote_token' => ['Báo giá vận chuyển không hợp lệ hoặc đã hết hạn!'],
        ]);
    }
}
