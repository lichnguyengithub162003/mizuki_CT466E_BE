<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPromotionAdminBranch(string $prefix = 'ADM'): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => $prefix.$token,
        'name' => 'Mizuki Admin '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function promotionAdminPayload(Branch $branch, array $overrides = []): array
{
    $token = Str::upper(Str::random(8));

    return array_merge([
        'code' => 'ADMIN'.$token,
        'name' => 'Admin Promotion '.$token,
        'description' => 'Promotion dùng trong kiểm thử quản trị.',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'max_discount_amount' => 100_000,
        'minimum_order_amount' => 200_000,
        'usage_limit' => 10,
        'usage_count' => 0,
        'per_user_limit' => 1,
        'applies_to' => 'order',
        'rules' => null,
        'starts_at' => now()->subMinute()->toISOString(),
        'ends_at' => now()->addMonth()->toISOString(),
        'is_active' => true,
        'branch_ids' => [$branch->id],
    ], $overrides);
}

function createManagedPromotion(Branch $branch, array $overrides = []): Promotion
{
    $payload = promotionAdminPayload($branch, $overrides);
    unset($payload['branch_ids'], $payload['user_ids']);

    $promotion = Promotion::query()->create($payload + ['scope' => null]);
    $promotion->branches()->attach($branch->id);

    return $promotion;
}

test('super admin can create update and delete a campaign for any branch', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'branch_id' => null]);
    $firstBranch = createPromotionAdminBranch('SA');
    $secondBranch = createPromotionAdminBranch('SB');
    $payload = promotionAdminPayload($firstBranch);
    $this->actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/promotions', $payload)
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.branch_ids.0', $firstBranch->id);

    $promotionId = (int) $createResponse->json('data.id');

    $this->patchJson("/api/v1/admin/promotions/{$promotionId}", [
        'name' => 'Campaign đã cập nhật',
        'branch_ids' => [$secondBranch->id],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Campaign đã cập nhật')
        ->assertJsonPath('data.branch_ids.0', $secondBranch->id);

    $this->assertDatabaseMissing('promotion_branches', [
        'promotion_id' => $promotionId,
        'branch_id' => $firstBranch->id,
    ]);

    $this->deleteJson("/api/v1/admin/promotions/{$promotionId}")
        ->assertOk()
        ->assertJsonPath('message', 'Xóa promotion thành công!');

    $this->assertDatabaseMissing('promotions', ['id' => $promotionId]);
    $this->assertDatabaseMissing('promotion_branches', ['promotion_id' => $promotionId]);
});

test('super admin can create a personal voucher', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $branch = createPromotionAdminBranch();
    $payload = promotionAdminPayload($branch);
    unset($payload['branch_ids']);
    $payload['user_ids'] = [$customer->id];
    $this->actingAs($admin);

    $response = $this->postJson('/api/v1/admin/promotions', $payload)
        ->assertCreated()
        ->assertJsonPath('data.user_ids.0', $customer->id)
        ->assertJsonCount(0, 'data.branch_ids');

    $promotion = Promotion::query()->findOrFail($response->json('data.id'));

    expect($promotion->scope)->toBe(['user_ids' => [$customer->id]]);
});

test('branch manager can create a campaign only for their own branch', function (): void {
    $branch = createPromotionAdminBranch();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);
    $this->actingAs($manager);

    $this->postJson('/api/v1/admin/promotions', promotionAdminPayload($branch))
        ->assertCreated()
        ->assertJsonPath('data.branch_ids.0', $branch->id);
});

test('branch manager cannot create or update a campaign for another branch', function (): void {
    $ownBranch = createPromotionAdminBranch('OWN');
    $otherBranch = createPromotionAdminBranch('OTH');
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $otherPromotion = createManagedPromotion($otherBranch);
    $ownPromotion = createManagedPromotion($ownBranch);
    $this->actingAs($manager);

    $this->postJson('/api/v1/admin/promotions', promotionAdminPayload($otherBranch))
        ->assertForbidden();

    $this->patchJson("/api/v1/admin/promotions/{$otherPromotion->id}", ['name' => 'Không được sửa'])
        ->assertForbidden();

    $this->patchJson("/api/v1/admin/promotions/{$ownPromotion->id}", [
        'branch_ids' => [$otherBranch->id],
    ])->assertForbidden();
});

test('branch manager cannot create or update a personal voucher', function (): void {
    $branch = createPromotionAdminBranch();
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $payload = promotionAdminPayload($branch);
    unset($payload['branch_ids']);
    $payload['user_ids'] = [$customer->id];
    $personal = createManagedPromotion($branch, [
        'scope' => ['user_ids' => [$customer->id]],
    ]);
    $this->actingAs($manager);

    $this->postJson('/api/v1/admin/promotions', $payload)->assertForbidden();
    $this->patchJson("/api/v1/admin/promotions/{$personal->id}", ['name' => 'Không được sửa'])
        ->assertForbidden();
});

test('branch manager list is scoped to public campaigns for their branch', function (): void {
    $ownBranch = createPromotionAdminBranch('LST');
    $otherBranch = createPromotionAdminBranch('EXT');
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $ownPromotion = createManagedPromotion($ownBranch, ['discount_type' => 'fixed_amount']);
    createManagedPromotion($otherBranch);
    createManagedPromotion($ownBranch, ['scope' => ['user_ids' => [$customer->id]]]);
    $this->actingAs($manager);

    $this->getJson('/api/v1/admin/promotions?discount_type=fixed_amount&is_active=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownPromotion->id)
        ->assertJsonPath('meta.pagination.total', 1);
});

test('usage stats count actual promotion usages and return zero before orders exist', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $branch = createPromotionAdminBranch();
    $promotion = createManagedPromotion($branch, ['usage_limit' => 10, 'usage_count' => 7]);
    $this->actingAs($admin);

    $this->getJson("/api/v1/admin/promotions/{$promotion->id}/usage-stats")
        ->assertOk()
        ->assertJsonPath('data.promotion_id', $promotion->id)
        ->assertJsonPath('data.usage_count', 0)
        ->assertJsonPath('data.usage_limit', 10)
        ->assertJsonPath('data.remaining_uses', 10);
});

test('customer cashier and technician cannot access any promotion admin endpoint', function (UserRole $role): void {
    $user = User::factory()->create(['role' => $role]);
    $branch = createPromotionAdminBranch();
    $promotion = createManagedPromotion($branch);
    $this->actingAs($user);

    $this->getJson('/api/v1/admin/promotions')->assertForbidden();
    $this->postJson('/api/v1/admin/promotions', promotionAdminPayload($branch))->assertForbidden();
    $this->patchJson("/api/v1/admin/promotions/{$promotion->id}", ['name' => 'Denied'])->assertForbidden();
    $this->deleteJson("/api/v1/admin/promotions/{$promotion->id}")->assertForbidden();
    $this->getJson("/api/v1/admin/promotions/{$promotion->id}/usage-stats")->assertForbidden();
})->with([
    'customer' => UserRole::Customer,
    'cashier' => UserRole::Cashier,
    'technician' => UserRole::Technician,
]);

test('guest cannot access promotion admin endpoints', function (): void {
    $this->getJson('/api/v1/admin/promotions')->assertUnauthorized();
    $this->postJson('/api/v1/admin/promotions', [])->assertUnauthorized();
    $this->patchJson('/api/v1/admin/promotions/1', [])->assertUnauthorized();
    $this->deleteJson('/api/v1/admin/promotions/1')->assertUnauthorized();
    $this->getJson('/api/v1/admin/promotions/1/usage-stats')->assertUnauthorized();
});

test('promotion validation enforces unique codes dates and percentage range', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $branch = createPromotionAdminBranch();
    $existing = createManagedPromotion($branch);
    $this->actingAs($admin);

    $this->postJson('/api/v1/admin/promotions', promotionAdminPayload($branch, [
        'code' => $existing->code,
        'discount_value' => 101,
        'starts_at' => now()->addDay()->toISOString(),
        'ends_at' => now()->toISOString(),
    ]))
        ->assertUnprocessable()
        ->assertJsonStructure([
            'data' => [
                'errors' => ['code', 'discount_value', 'ends_at'],
            ],
        ]);
});
