<?php

use App\Support\Import\ClinicServiceJsonMapper;

beforeEach(function (): void {
    $this->mapper = new ClinicServiceJsonMapper;
    $this->record = static fn (array $overrides = []): array => array_replace([
        'sourceId' => '00123',
        'sku' => '0000456',
        'name' => 'Deep Skin Care',
        'description' => 'Detailed description',
        'shortDescription' => 'Short description',
        'price' => 450000,
        'durationMinutes' => 60,
        'durationText' => "1 l\u{1EA7}n | 60 ph\u{00FA}t",
        'image' => 'https://example.test/service.jpg',
        'serviceType' => 'Skin Care',
        'categoryPath' => ['Clinic', 'Skin Care'],
    ], $overrides);
});

test('mapper preserves string identities and maps a numeric duration', function (): void {
    $mapped = $this->mapper->map(($this->record)());

    expect($mapped['status'])->toBe('valid')
        ->and($mapped['source_id'])->toBe('00123')
        ->and($mapped['sku'])->toBe('0000456')
        ->and($mapped['duration_source'])->toBe('numeric')
        ->and($mapped['slug'])->toBe('hasaki-clinic-00123')
        ->and($mapped['service'])->toMatchArray([
            'name' => 'Deep Skin Care',
            'description' => 'Detailed description',
            'short_description' => 'Short description',
            'price' => 450000,
            'duration_minutes' => 60,
            'image_url' => 'https://example.test/service.jpg',
            'category' => 'skin_care',
        ]);
});

test('mapper safely parses only a clearly fixed duration', function (): void {
    $record = ($this->record)([
        'durationMinutes' => null,
        'durationText' => "1 l\u{1EA7}n | 75 ph\u{00FA}t",
    ]);
    $mapped = $this->mapper->map($record);

    expect($mapped['status'])->toBe('valid')
        ->and($mapped['duration_source'])->toBe('safely_parsed')
        ->and($mapped['service']['duration_minutes'])->toBe(75);
});

test('service name changes do not change source identity slug', function (): void {
    $original = $this->mapper->map(($this->record)(['name' => 'Original Service Name']));
    $renamed = $this->mapper->map(($this->record)(['name' => 'Renamed Service']));

    expect($original['source_id'])->toBe('00123')
        ->and($renamed['source_id'])->toBe('00123')
        ->and($original['slug'])->toBe('hasaki-clinic-00123')
        ->and($renamed['slug'])->toBe($original['slug']);
});

test('mapper quarantines a duration range without choosing a bound', function (): void {
    $record = ($this->record)([
        'durationMinutes' => null,
        'durationText' => "1 l\u{1EA7}n | 57-127 ph\u{00FA}t",
    ]);
    $mapped = $this->mapper->map($record);

    expect($mapped['status'])->toBe('quarantined')
        ->and($mapped['duration_source'])->toBe('range')
        ->and($mapped['reason'])->toBe('duration_range_not_allowed')
        ->and($mapped)->not->toHaveKey('service');
});

test('mapper quarantines an unparseable duration', function (): void {
    $record = ($this->record)([
        'durationMinutes' => null,
        'durationText' => "Li\u{00EA}n h\u{1EC7} ph\u{00F2}ng kh\u{00E1}m",
    ]);
    $mapped = $this->mapper->map($record);

    expect($mapped['status'])->toBe('quarantined')
        ->and($mapped['duration_source'])->toBe('unparseable')
        ->and($mapped['reason'])->toBe('duration_unparseable');
});

test('mapper rejects missing required source identity and invalid price', function (): void {
    $missingIdentity = $this->mapper->map(($this->record)(['sourceId' => null]));
    $invalidPrice = $this->mapper->map(($this->record)(['price' => 0]));

    expect($missingIdentity['status'])->toBe('failed')
        ->and($missingIdentity['reason'])->toBe('missing_source_id')
        ->and($invalidPrice['status'])->toBe('failed')
        ->and($invalidPrice['reason'])->toBe('invalid_price');
});
