<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'provider',
    'ghn_order_code',
    'status',
    'shipping_fee',
    'provider_response',
    'expected_delivery_at',
    'shipped_at',
    'delivered_at',
    'cancelled_at',
])]
class Shipment extends Model
{
    /** @var array<string, list<string>> */
    private const MANUAL_TRANSITIONS = [
        'pending' => ['in_transit'],
        'ready_to_pick' => ['in_transit'],
        'picking' => ['in_transit'],
        'in_transit' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered', 'delivery_failed'],
    ];

    /** @var array<string, string> */
    private const GHN_STATUS_MAP = [
        'ready_to_pick' => 'ready_to_pick',
        'picking' => 'picking',
        'money_collect_picking' => 'picking',
        'picked' => 'in_transit',
        'storing' => 'in_transit',
        'transporting' => 'in_transit',
        'sorting' => 'in_transit',
        'delivering' => 'out_for_delivery',
        'money_collect_delivering' => 'out_for_delivery',
        'delivered' => 'delivered',
        'delivery_fail' => 'delivery_failed',
        'waiting_to_return' => 'returning',
        'return' => 'returning',
        'return_transporting' => 'returning',
        'return_sorting' => 'returning',
        'returning' => 'returning',
        'return_fail' => 'failed',
        'returned' => 'returned',
        'cancel' => 'cancelled',
        'exception' => 'failed',
        'damage' => 'failed',
        'lost' => 'failed',
    ];

    /** @var array<string, int> */
    private const STATUS_RANK = [
        'pending' => 0,
        'ready_to_pick' => 10,
        'picking' => 20,
        'in_transit' => 30,
        'out_for_delivery' => 40,
        'delivery_failed' => 45,
        'returning' => 50,
        'delivered' => 100,
        'returned' => 100,
        'cancelled' => 100,
        'failed' => 100,
    ];

    /** @var list<string> */
    private const TERMINAL_STATUSES = ['delivered', 'returned', 'cancelled', 'failed'];

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'pending' => 'Chờ tạo vận đơn',
        'ready_to_pick' => 'Chờ lấy hàng',
        'picking' => 'Đang lấy hàng',
        'in_transit' => 'Đang vận chuyển',
        'out_for_delivery' => 'Đang giao hàng',
        'delivery_failed' => 'Giao hàng thất bại',
        'returning' => 'Đang hoàn hàng',
        'delivered' => 'Đã giao hàng',
        'returned' => 'Đã hoàn hàng',
        'cancelled' => 'Đã hủy vận đơn',
        'failed' => 'Vận chuyển thất bại',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_fee' => 'integer',
            'provider_response' => 'array',
            'expected_delivery_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function mappedGhnStatus(string $providerStatus): ?string
    {
        return self::GHN_STATUS_MAP[$providerStatus] ?? null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function providerStatus(): ?string
    {
        $status = data_get($this->provider_response, 'Status')
            ?? data_get($this->provider_response, 'status');

        return is_string($status) && filled($status) ? $status : null;
    }

    public function logisticsStage(): string
    {
        return match ($this->providerStatus()) {
            'ready_to_pick', 'picking', 'money_collect_picking' => 'waiting_pickup',
            'picked' => 'picked_up',
            'storing', 'transporting', 'sorting' => 'in_transit',
            'delivering', 'money_collect_delivering' => 'out_for_delivery',
            'delivery_fail' => 'delivery_failed',
            'waiting_to_return' => 'waiting_return',
            'return', 'return_transporting', 'return_sorting', 'returning' => 'returning',
            'returned' => 'returned',
            'delivered' => 'delivered',
            'exception', 'damage', 'lost', 'return_fail' => 'exception',
            'cancel' => 'cancelled',
            default => match ($this->status) {
                'pending', 'ready_to_pick', 'picking' => 'waiting_pickup',
                'in_transit' => 'in_transit',
                'out_for_delivery' => 'out_for_delivery',
                'delivery_failed' => 'delivery_failed',
                'returning' => 'returning',
                'returned' => 'returned',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled',
                default => 'exception',
            },
        };
    }

    public function logisticsStageLabel(): string
    {
        return match ($this->logisticsStage()) {
            'waiting_pickup' => 'Chờ lấy hàng',
            'picked_up' => 'Đã lấy hàng',
            'in_transit' => 'Đang trung chuyển',
            'out_for_delivery' => 'Đang giao hàng',
            'delivery_failed' => 'Giao thất bại',
            'waiting_return' => 'Chờ trả hàng',
            'returning' => 'Đang trả hàng',
            'returned' => 'Đã trả hàng',
            'delivered' => 'Đã giao hàng',
            'cancelled' => 'Đã hủy vận đơn',
            default => 'Ngoại lệ',
        };
    }

    public function canTransitionTo(string $status): bool
    {
        if ($status === $this->status) {
            return true;
        }

        if (in_array($this->status, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        return isset(self::STATUS_RANK[$status])
            && (self::STATUS_RANK[$status] >= (self::STATUS_RANK[$this->status] ?? -1));
    }

    /** @return list<string> */
    public function manualTransitionTargets(): array
    {
        return self::MANUAL_TRANSITIONS[$this->status] ?? [];
    }

    public function canManualTransitionTo(string $status): bool
    {
        return $status === $this->status
            || in_array($status, $this->manualTransitionTargets(), true);
    }

    public function applyManualTransition(string $status): bool
    {
        if (! $this->canManualTransitionTo($status)) {
            return false;
        }

        $attributes = ['status' => $status];

        if (in_array($status, ['in_transit', 'out_for_delivery'], true)) {
            $attributes['shipped_at'] = $this->shipped_at ?? now();
        } elseif ($status === 'delivered') {
            $attributes['shipped_at'] = $this->shipped_at ?? now();
            $attributes['delivered_at'] = $this->delivered_at ?? now();
        }

        $this->fill($attributes);

        return true;
    }

    /** @param array<string, mixed> $payload */
    public function applyGhnWebhook(string $providerStatus, array $payload): bool
    {
        $status = self::mappedGhnStatus($providerStatus);

        if ($status === null || ! $this->canTransitionTo($status)) {
            return false;
        }

        $attributes = ['status' => $status];
        $normalizedPayload = self::normalizeProviderResponse($payload);
        $currentPayload = is_array($this->provider_response)
            ? self::normalizeProviderResponse($this->provider_response)
            : null;

        if ($currentPayload !== $normalizedPayload) {
            $attributes['provider_response'] = $normalizedPayload;
        }

        if ($status === 'in_transit' || $status === 'out_for_delivery') {
            $attributes['shipped_at'] = $this->shipped_at ?? now();
        } elseif ($status === 'delivered') {
            $attributes['shipped_at'] = $this->shipped_at ?? now();
            $attributes['delivered_at'] = $this->delivered_at ?? now();
        } elseif ($status === 'cancelled') {
            $attributes['cancelled_at'] = $this->cancelled_at ?? now();
        }

        $this->fill($attributes);

        return true;
    }

    /**
     * Sort object-like arrays recursively while preserving meaningful list order.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function normalizeProviderResponse(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::normalizeProviderResponse($value);
            }
        }

        if (! array_is_list($payload)) {
            ksort($payload, SORT_STRING);
        }

        return $payload;
    }
}
