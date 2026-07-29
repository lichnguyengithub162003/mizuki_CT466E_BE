<?php

use App\Enums\BranchType;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;
use App\Services\Import\ClinicServiceJsonImportService;
use App\Support\Import\ClinicServiceJsonMapper;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function createClinicJsonImportBranch(
    BranchType $type = BranchType::Hybrid,
    bool $active = true,
): Branch {
    return Branch::query()->create([
        'code' => 'IMPORT-'.strtoupper($type->value).'-'.Branch::query()->count(),
        'name' => 'Import Target Branch',
        'branch_type' => $type,
        'phone' => '02920000000',
        'address' => 'Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => $active,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clinicJsonImportRecord(array $overrides = []): array
{
    return array_replace([
        'sourceId' => '1001',
        'sku' => '90001001',
        'name' => 'Clinic Service 1001',
        'description' => 'Service description',
        'shortDescription' => 'Short description',
        'price' => 300000,
        'durationMinutes' => 60,
        'durationText' => "1 l\u{1EA7}n | 60 ph\u{00FA}t",
        'image' => 'https://example.test/service.jpg',
        'serviceType' => 'Skin Care',
        'categoryPath' => ['Clinic', 'Skin Care'],
    ], $overrides);
}

test('dry-run analyzes the complete source and performs no database or storage writes', function (): void {
    Storage::fake('local');
    $branch = createClinicJsonImportBranch();
    $serviceCount = Service::query()->withTrashed()->count();
    $attachmentCount = BranchService::query()->count();

    $this->artisan('import:clinic-services', [
        '--dry-run' => true,
        '--branch' => $branch->id,
    ])
        ->expectsOutput('Total records: 186')
        ->expectsOutput('Valid planned inserts: 145')
        ->expectsOutput('Valid planned updates: 0')
        ->expectsOutput('Quarantined: 41')
        ->expectsOutput('Failed: 0')
        ->expectsOutput('Duplicate source IDs: 0')
        ->expectsOutput('Duplicate slugs: 0')
        ->expectsOutput('Planned branch attachments: 145')
        ->expectsOutput('Numeric: 142')
        ->expectsOutput('Safely parsed: 3')
        ->expectsOutput('Range: 6')
        ->expectsOutput('Unparseable: 35')
        ->assertSuccessful();

    expect(Service::query()->withTrashed()->count())->toBe($serviceCount)
        ->and(BranchService::query()->count())->toBe($attachmentCount)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('declined write confirmation performs no database writes', function (): void {
    $branch = createClinicJsonImportBranch();
    $serviceCount = Service::query()->withTrashed()->count();
    $attachmentCount = BranchService::query()->count();

    $this->artisan('import:clinic-services', ['--branch' => $branch->id])
        ->expectsConfirmation(
            "Import 145 valid clinic services into branch {$branch->id}?",
            'no',
        )
        ->expectsOutput('Import cancelled: no database writes were performed.')
        ->expectsOutput('Rolled back: no')
        ->assertSuccessful();

    expect(Service::query()->withTrashed()->count())->toBe($serviceCount)
        ->and(BranchService::query()->count())->toBe($attachmentCount);
});

test('branch option is mandatory', function (): void {
    $this->artisan('import:clinic-services', ['--dry-run' => true])
        ->expectsOutput('The --branch option is required and must be a positive integer.')
        ->assertExitCode(Command::INVALID);
});

test('missing inactive and store-only branches are rejected', function (?BranchType $type, bool $active, int $missingId): void {
    $branchId = $type === null
        ? $missingId
        : createClinicJsonImportBranch($type, $active)->id;

    $this->artisan('import:clinic-services', [
        '--dry-run' => true,
        '--branch' => $branchId,
    ])
        ->expectsOutput('Branch not found, inactive, or does not support clinic services.')
        ->assertFailed();
})->with([
    'missing branch' => [null, true, 999999],
    'inactive clinic' => [BranchType::Clinic, false, 0],
    'store-only branch' => [BranchType::Store, true, 0],
]);

test('invalid JSON and non-array roots are rejected clearly', function (string $json, string $message): void {
    $branch = createClinicJsonImportBranch();
    $service = app(ClinicServiceJsonImportService::class);

    expect(fn () => $service->analyzeJson($json, $branch))
        ->toThrow(UnexpectedValueException::class, $message);
})->with([
    'invalid JSON' => ['{"broken":', 'Source JSON is invalid:'],
    'object root' => ['{"services":[]}', 'Source JSON root must be an array.'],
]);

test('same source identity with different names resolves to one slug and is rejected as duplicate', function (): void {
    $branch = createClinicJsonImportBranch();
    $records = [
        clinicJsonImportRecord(),
        clinicJsonImportRecord(['name' => 'Duplicate Record']),
        clinicJsonImportRecord([
            'sourceId' => '1002',
            'sku' => '90001002',
            'name' => 'Unparseable Duration',
            'durationMinutes' => null,
            'durationText' => 'Contact clinic',
        ]),
    ];

    $result = app(ClinicServiceJsonImportService::class)->analyzeJson(
        json_encode($records, JSON_THROW_ON_ERROR),
        $branch,
    );

    expect($result)->toMatchArray([
        'total' => 3,
        'planned_inserts' => 1,
        'planned_updates' => 0,
        'quarantined' => 1,
        'failed' => 1,
        'duplicate_source_ids' => 1,
        'duplicate_slugs' => 1,
        'planned_branch_attachments' => 1,
    ])->and($result['duration_sources'])->toBe([
        'numeric' => 2,
        'safely_parsed' => 0,
        'range' => 0,
        'unparseable' => 1,
    ]);
});

test('deterministic slug collisions are detected separately', function (): void {
    $branch = createClinicJsonImportBranch();
    $records = [
        clinicJsonImportRecord(['sourceId' => 'A/B']),
        clinicJsonImportRecord(['sourceId' => 'A-B', 'sku' => '90001002']),
    ];

    $result = app(ClinicServiceJsonImportService::class)->analyzeJson(
        json_encode($records, JSON_THROW_ON_ERROR),
        $branch,
    );

    expect($result['duplicate_source_ids'])->toBe(0)
        ->and($result['duplicate_slugs'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and($result['planned_inserts'])->toBe(1);
});

test('existing deterministic services are planned as updates without being changed', function (): void {
    $branch = createClinicJsonImportBranch();
    $record = clinicJsonImportRecord();
    $mapped = app(ClinicServiceJsonMapper::class)->map($record);
    $existing = Service::query()->create($mapped['service']);
    BranchService::query()->create([
        'branch_id' => $branch->id,
        'service_id' => $existing->id,
        'is_available' => false,
        'capacity' => 3,
    ]);
    $beforeUpdatedAt = $existing->updated_at?->toISOString();

    $result = app(ClinicServiceJsonImportService::class)->analyzeJson(
        json_encode([$record], JSON_THROW_ON_ERROR),
        $branch,
    );

    expect($result)->toMatchArray([
        'planned_inserts' => 0,
        'planned_updates' => 1,
        'planned_branch_attachments' => 0,
        'default_capacity' => 1,
    ])->and(Service::query()->count())->toBe(1)
        ->and($existing->refresh()->name)->toBe('Clinic Service 1001')
        ->and($existing->updated_at?->toISOString())->toBe($beforeUpdatedAt)
        ->and($existing->branchServices()->first()->capacity)->toBe(3)
        ->and($existing->branchServices()->first()->is_available)->toBeFalse();
});

describe('write mode clinic importer', function (): void {
    test('write mode creates valid services and selected branch links but skips quarantined records', function (): void {
        $branch = createClinicJsonImportBranch();
        $otherBranch = createClinicJsonImportBranch(BranchType::Clinic);
        $records = [
            clinicJsonImportRecord(),
            clinicJsonImportRecord([
                'sourceId' => '1002',
                'sku' => '90001002',
                'durationMinutes' => null,
                'durationText' => '1 lần | 30-60 phút',
            ]),
        ];

        $result = app(ClinicServiceJsonImportService::class)->importJson(
            json_encode($records, JSON_THROW_ON_ERROR),
            $branch,
        );
        $service = Service::query()->where('slug', 'hasaki-clinic-1001')->firstOrFail();

        expect($result)->toMatchArray([
            'valid' => 1,
            'quarantined' => 1,
            'failed' => 0,
            'created_services' => 1,
            'updated_services' => 0,
            'unchanged_services' => 0,
            'created_branch_service_links' => 1,
            'updated_branch_service_links' => 0,
            'unchanged_branch_service_links' => 0,
            'rolled_back' => false,
        ])->and(Service::query()->where('slug', 'hasaki-clinic-1002')->exists())->toBeFalse()
            ->and(BranchService::query()->where([
                'branch_id' => $branch->id,
                'service_id' => $service->id,
            ])->exists())->toBeTrue()
            ->and(BranchService::query()->where([
                'branch_id' => $otherBranch->id,
                'service_id' => $service->id,
            ])->exists())->toBeFalse();
    });

    test('write mode is idempotent and updates changed names under the stable source slug', function (): void {
        $branch = createClinicJsonImportBranch();
        $service = app(ClinicServiceJsonImportService::class);
        $originalJson = json_encode([clinicJsonImportRecord()], JSON_THROW_ON_ERROR);

        $first = $service->importJson($originalJson, $branch);
        $second = $service->importJson($originalJson, $branch);
        $renamed = $service->importJson(json_encode([
            clinicJsonImportRecord(['name' => 'Renamed Clinic Service']),
        ], JSON_THROW_ON_ERROR), $branch);

        expect($first['created_services'])->toBe(1)
            ->and($second['created_services'])->toBe(0)
            ->and($second['unchanged_services'])->toBe(1)
            ->and($second['unchanged_branch_service_links'])->toBe(1)
            ->and($renamed['updated_services'])->toBe(1)
            ->and(Service::query()->where('slug', 'hasaki-clinic-1001')->count())->toBe(1)
            ->and(Service::query()->where('slug', 'hasaki-clinic-1001')->value('name'))
            ->toBe('Renamed Clinic Service')
            ->and(BranchService::query()->count())->toBe(1);
    });

    test('write mode preserves existing branch service operational overrides', function (): void {
        $branch = createClinicJsonImportBranch();
        $mapped = app(ClinicServiceJsonMapper::class)->map(clinicJsonImportRecord());
        $service = Service::query()->create($mapped['service']);
        $link = BranchService::query()->create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'is_available' => false,
            'capacity' => 7,
        ]);

        $result = app(ClinicServiceJsonImportService::class)->importJson(
            json_encode([clinicJsonImportRecord(['name' => 'Updated Catalog Name'])], JSON_THROW_ON_ERROR),
            $branch,
        );

        expect($result['updated_services'])->toBe(1)
            ->and($result['updated_branch_service_links'])->toBe(0)
            ->and($result['unchanged_branch_service_links'])->toBe(1)
            ->and($link->refresh()->is_available)->toBeFalse()
            ->and($link->capacity)->toBe(7);
    });

    test('force bypasses confirmation and prints accurate write counters', function (): void {
        $branch = createClinicJsonImportBranch();

        $this->artisan('import:clinic-services', [
            '--branch' => $branch->id,
            '--force' => true,
        ])
            ->expectsOutput('Valid records: 145')
            ->expectsOutput('Created services: 145')
            ->expectsOutput('Updated services: 0')
            ->expectsOutput('Unchanged services: 0')
            ->expectsOutput('Created branch-service links: 145')
            ->expectsOutput('Rolled back: no')
            ->assertSuccessful();

        expect(Service::query()->where('slug', 'like', 'hasaki-clinic-%')->count())->toBe(145)
            ->and(BranchService::query()->where('branch_id', $branch->id)->count())->toBe(145);
    });

    test('fatal write failure rolls back the complete command execution', function (): void {
        $branch = createClinicJsonImportBranch();
        $eventName = 'eloquent.creating: '.Service::class;
        $creating = 0;

        Event::listen($eventName, function () use (&$creating): void {
            $creating++;

            if ($creating === 2) {
                throw new RuntimeException('Forced importer write failure.');
            }
        });

        try {
            $this->artisan('import:clinic-services', [
                '--branch' => $branch->id,
                '--force' => true,
            ])
                ->expectsOutput('Import failed: Forced importer write failure.')
                ->expectsOutput('Rolled back: yes')
                ->assertFailed();
        } finally {
            Event::forget($eventName);
        }

        expect(Service::query()->withTrashed()->count())->toBe(0)
            ->and(BranchService::query()->count())->toBe(0);
    });

    test('imported services are exposed by the existing public clinic API', function (): void {
        $branch = createClinicJsonImportBranch();
        app(ClinicServiceJsonImportService::class)->importJson(
            json_encode([clinicJsonImportRecord()], JSON_THROW_ON_ERROR),
            $branch,
        );

        $this->getJson("/api/v1/clinics/{$branch->id}/services")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'hasaki-clinic-1001')
            ->assertJsonPath('data.0.capacity', 1)
            ->assertJsonPath('data.0.is_available', true);
    });
});
