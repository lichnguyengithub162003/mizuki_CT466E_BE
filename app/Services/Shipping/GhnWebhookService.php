<?php

namespace App\Services\Shipping;

use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Validation\ValidationException;
use JsonException;

class GhnWebhookService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{shipment: Shipment, changed: bool}|null
     */
    public function handle(array $payload): ?array
    {
        $this->validatePayloadSize($payload);
        $orderCode = $this->requiredString($payload, 'OrderCode', 100);
        $providerStatus = $this->requiredString($payload, 'Status', 50);

        if (Shipment::mappedGhnStatus($providerStatus) === null) {
            throw ValidationException::withMessages([
                'Status' => ['Trạng thái GHN không được hỗ trợ'],
            ]);
        }

        return $this->shipments->applyGhnWebhook($orderCode, $providerStatus, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function validatePayloadSize(array $payload): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'payload' => ['Payload webhook GHN không hợp lệ'],
            ]);
        }

        if (strlen($encoded) > 65_536) {
            throw ValidationException::withMessages([
                'payload' => ['Payload webhook GHN vượt quá kích thước cho phép'],
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $field, int $maximum): string
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maximum) {
            throw ValidationException::withMessages([
                $field => ["{$field} không hợp lệ"],
            ]);
        }

        return trim($value);
    }
}
