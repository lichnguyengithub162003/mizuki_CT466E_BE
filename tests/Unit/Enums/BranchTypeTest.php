<?php

use App\Enums\BranchType;

test('it defines stable branch type values and Vietnamese labels', function (): void {
    expect(BranchType::cases())->toHaveCount(3)
        ->and(BranchType::Store->value)->toBe('store')
        ->and(BranchType::Clinic->value)->toBe('clinic')
        ->and(BranchType::Hybrid->value)->toBe('hybrid')
        ->and(BranchType::Store->label())->toBe("C\u{1EED}a h\u{00E0}ng")
        ->and(BranchType::Clinic->label())->toBe("Ph\u{00F2}ng kh\u{00E1}m")
        ->and(BranchType::Hybrid->label())->toBe("C\u{1EED}a h\u{00E0}ng v\u{00E0} ph\u{00F2}ng kh\u{00E1}m");
});

test('it reports retail support by branch type', function (): void {
    expect(BranchType::Store->supportsRetail())->toBeTrue()
        ->and(BranchType::Hybrid->supportsRetail())->toBeTrue()
        ->and(BranchType::Clinic->supportsRetail())->toBeFalse();
});

test('it reports clinic support by branch type', function (): void {
    expect(BranchType::Clinic->supportsClinic())->toBeTrue()
        ->and(BranchType::Hybrid->supportsClinic())->toBeTrue()
        ->and(BranchType::Store->supportsClinic())->toBeFalse();
});
