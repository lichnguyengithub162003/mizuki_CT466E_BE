<?php

use App\Services\Shipping\GhnServiceSelector;
use Illuminate\Validation\ValidationException;

test('deterministically prefers the lowest light parcel service ID', function (): void {
    $services = [
        ['service_id' => 900, 'short_name' => 'Heavy', 'service_type_id' => 5],
        ['service_id' => 53400, 'short_name' => 'Light B', 'service_type_id' => 2],
        ['service_id' => 53320, 'short_name' => 'Light A', 'service_type_id' => 2],
    ];

    expect(app(GhnServiceSelector::class)->select($services))->toBe([
        'service_id' => 53320,
        'short_name' => 'Light A',
        'service_type_id' => 2,
    ]);
});

test('falls back deterministically when no light parcel service is returned', function (): void {
    $services = [
        ['service_id' => 902, 'short_name' => 'Heavy B', 'service_type_id' => 5],
        ['service_id' => 901, 'short_name' => 'Heavy A', 'service_type_id' => 5],
    ];

    expect(app(GhnServiceSelector::class)->select($services)['service_id'])->toBe(901);
});

test('rejects an empty available service list', function (): void {
    expect(fn (): array => app(GhnServiceSelector::class)->select([]))
        ->toThrow(ValidationException::class);
});
