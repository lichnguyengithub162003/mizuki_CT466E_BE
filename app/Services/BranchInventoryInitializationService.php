<?php

namespace App\Services;

use App\Enums\BranchType;
use App\Models\Branch;
use App\Repositories\BranchInventoryInitializationRepository;
use InvalidArgumentException;

class BranchInventoryInitializationService extends BaseService
{
    /** @var list<array<string, mixed>> */
    private const BRANCHES = [
        [
            'code' => 'MZ-NK-01',
            'name' => 'Mizuki Ninh Kiều',
            'address' => '51 Đường 3/2, Phường Xuân Khánh, Quận Ninh Kiều, Cần Thơ',
            'province_code' => '710',
            'ghn_district_id' => 1572,
            'ghn_ward_code' => '550113',
            'phone' => '02923730101',
            'email' => 'ninhkieu@mizuki.vn',
            'legacy_codes' => ['DEV-CT'],
        ],
        [
            'code' => 'MZ-CR-01',
            'name' => 'Mizuki Cái Răng',
            'address' => '18 Nguyễn Thị Sáu, Phường Lê Bình, Quận Cái Răng, Cần Thơ',
            'province_code' => '710',
            'ghn_district_id' => 1574,
            'ghn_ward_code' => '550304',
            'phone' => '02923730202',
            'email' => 'cairang@mizuki.vn',
            'legacy_codes' => ['DEV-CLINIC-CT', 'MZ-SKIN-NK-01'],
        ],
        [
            'code' => 'MZ-BT-01',
            'name' => 'Mizuki Bình Thủy',
            'address' => '86 Lê Hồng Phong, Phường Bình Thủy, Quận Bình Thủy, Cần Thơ',
            'province_code' => '710',
            'ghn_district_id' => 1573,
            'ghn_ward_code' => '550202',
            'phone' => '02923730303',
            'email' => 'binhthuy@mizuki.vn',
            'legacy_codes' => [],
        ],
        [
            'code' => 'MZ-OM-01',
            'name' => 'Mizuki Ô Môn',
            'address' => '42 Đường 26 Tháng 3, Phường Châu Văn Liêm, Quận Ô Môn, Cần Thơ',
            'province_code' => '710',
            'ghn_district_id' => 1575,
            'ghn_ward_code' => '550401',
            'phone' => '02923730404',
            'email' => 'omon@mizuki.vn',
            'legacy_codes' => [],
        ],
        [
            'code' => 'MZ-TN-01',
            'name' => 'Mizuki Thốt Nốt',
            'address' => '35 Quốc lộ 91, Phường Thốt Nốt, Quận Thốt Nốt, Cần Thơ',
            'province_code' => '710',
            'ghn_district_id' => 1576,
            'ghn_ward_code' => '550805',
            'phone' => '02923730505',
            'email' => 'thotnot@mizuki.vn',
            'legacy_codes' => [],
        ],
        [
            'code' => 'MZ-VL-01',
            'name' => 'Mizuki Vĩnh Long',
            'address' => '68 Đường Phạm Thái Bường, Phường 1, Thành phố Vĩnh Long, Vĩnh Long',
            'province_code' => '70',
            'ghn_district_id' => 1562,
            'ghn_ward_code' => '570101',
            'phone' => '02703730606',
            'email' => 'vinhlong@mizuki.vn',
            'legacy_codes' => [],
        ],
    ];

    public function __construct(
        private readonly BranchInventoryInitializationRepository $repository,
    ) {}

    /** @return array<string, mixed> */
    public function initialize(bool $dryRun, ?string $branchCode = null): array
    {
        $profiles = $this->profiles($branchCode);
        $branchActions = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $branches = [];

        foreach ($profiles as $profile) {
            $legacyCodes = $profile['legacy_codes'];
            unset($profile['legacy_codes']);
            $hours = $this->businessHours();

            if ($dryRun) {
                $branch = $this->repository->findForProfile($profile['code'], $legacyCodes);

                if ($branch === null) {
                    $branchActions['created']++;
                } else {
                    $branch->fill($profile);
                    $branchActions[$branch->trashed() || $branch->isDirty() ? 'updated' : 'unchanged']++;
                    $branches[] = $branch;
                }

                continue;
            }

            $resolved = $this->repository->transaction(function () use ($profile, $legacyCodes, $hours): array {
                $result = $this->repository->ensureBranch($profile, $legacyCodes);
                $this->repository->ensureBusinessHours($result['branch'], $hours);

                return $result;
            });
            $branchActions[$resolved['action']]++;
            $branches[] = $resolved['branch'];
        }

        $activeVariantCount = $this->repository->activeSellableVariantCount();
        $inventory = ['created' => 0, 'preserved' => 0];

        if (! $dryRun) {
            foreach ($branches as $branch) {
                $result = $this->repository->backfillMissingInventories($branch);
                $inventory['created'] += $result['created'];
                $inventory['preserved'] += $result['preserved'];
            }
        }

        $branchIds = array_map(fn (Branch $branch): int => (int) $branch->id, $branches);
        $integrity = $dryRun
            ? [
                'expected' => count($profiles) * $activeVariantCount,
                'actual' => $branchIds === [] ? 0 : $this->repository->inventoryIntegrity($branchIds)['actual'],
                'missing' => 0,
                'duplicates' => 0,
                'negative' => 0,
            ]
            : $this->repository->inventoryIntegrity($branchIds);

        if ($dryRun) {
            $integrity['missing'] = max(0, $integrity['expected'] - $integrity['actual']);
            $inventory['created'] = $integrity['missing'];
            $inventory['preserved'] = $integrity['actual'];
        }

        return [
            'dry_run' => $dryRun,
            'branch_scope' => $branchCode,
            'branches' => $branchActions,
            'active_branches' => count($profiles),
            'active_variants' => $activeVariantCount,
            'inventory' => $inventory,
            'integrity' => $integrity,
            'unsupported_profile_fields' => [
                'slug',
                'province_name',
                'district_name',
                'ward_name',
                'latitude',
                'longitude',
                'supports_pickup',
                'supports_delivery',
                'supports_services',
                'display_order',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function profiles(?string $branchCode): array
    {
        $profiles = array_map(fn (array $profile): array => $profile + [
            'branch_type' => BranchType::Hybrid->value,
            'is_active' => true,
        ], self::BRANCHES);

        if ($branchCode === null || $branchCode === '') {
            return $profiles;
        }

        $filtered = array_values(array_filter(
            $profiles,
            fn (array $profile): bool => $profile['code'] === strtoupper($branchCode),
        ));

        if ($filtered === []) {
            throw new InvalidArgumentException('Mã chi nhánh không thuộc danh sách khởi tạo Mizuki.');
        }

        return $filtered;
    }

    /** @return list<array{weekday: int, opens_at: string, closes_at: string, is_closed: bool}> */
    private function businessHours(): array
    {
        return array_map(
            fn (int $weekday): array => [
                'weekday' => $weekday,
                'opens_at' => $weekday === 0 ? '08:30:00' : '08:00:00',
                'closes_at' => '21:00:00',
                'is_closed' => false,
            ],
            range(0, 6),
        );
    }
}
