<?php

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->artisan('mizuki:initialize-branches-inventory')->assertSuccessful();
});

it('publicly returns only the six active branches in deterministic order', function (): void {
    $inactiveBranch = Branch::query()->create([
        'code' => 'MZ-INACTIVE',
        'name' => 'Mizuki Ngừng hoạt động',
        'address' => 'Địa chỉ không hoạt động',
        'phone' => '0292000000',
        'province_code' => '92',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '20101',
        'email' => 'inactive@mizuki.test',
        'is_active' => false,
    ]);

    $expectedBranches = Branch::query()
        ->where('is_active', true)
        ->orderBy('name')
        ->orderBy('id')
        ->get();

    expect($expectedBranches)->toHaveCount(6);

    $response = $this->getJson('/api/v1/branches');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Lấy danh sách chi nhánh thành công!')
        ->assertJsonCount(6, 'data')
        ->assertJsonMissing(['id' => $inactiveBranch->id]);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe($expectedBranches->pluck('id')->all());

    foreach ($response->json('data') as $branch) {
        expect($branch)
            ->toHaveKeys([
                'id',
                'code',
                'name',
                'address',
                'phone',
                'email',
                'is_active',
                'opening_hours',
            ])
            ->not->toHaveKeys(['inventories', 'manager', 'manager_id'])
            ->and($branch['is_active'])->toBeTrue()
            ->and($branch['opening_hours'])->toHaveCount(7);

        foreach ($branch['opening_hours'] as $hours) {
            expect($hours)->toHaveKeys([
                'weekday',
                'opens_at',
                'closes_at',
                'is_closed',
            ]);
        }
    }
});

it('eager loads business hours without an n plus one query', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->getJson('/api/v1/branches');

    $businessHoursQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'branch_business_hours',
        ));

    DB::disableQueryLog();

    $response->assertOk();
    expect($businessHoursQueries)->toHaveCount(1);
});
