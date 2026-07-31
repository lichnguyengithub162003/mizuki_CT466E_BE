<?php

use App\Support\Auth\PasswordRecoveryOtpGenerator;

test('local environment uses the configured demo OTP', function (): void {
    config()->set([
        'app.env' => 'local',
        'password_recovery.demo_code' => '123456',
    ]);

    expect((new PasswordRecoveryOtpGenerator)->generate())->toBe('123456');
});

test('testing environment uses the configured deterministic OTP', function (): void {
    config()->set([
        'app.env' => 'testing',
        'password_recovery.demo_code' => '654321',
    ]);

    expect((new PasswordRecoveryOtpGenerator)->generate())->toBe('654321');
});

test('non-local environments generate exactly six numeric characters', function (): void {
    config()->set([
        'app.env' => 'staging',
        'password_recovery.demo_code' => '123456',
    ]);

    $otp = (new PasswordRecoveryOtpGenerator(static fn (): int => 987_654))->generate();

    expect($otp)->toBe('987654')->toMatch('/\A[0-9]{6}\z/');
});

test('secure OTP formatting preserves leading zeroes', function (): void {
    config()->set([
        'app.env' => 'production',
        'password_recovery.demo_code' => '123456',
    ]);

    expect((new PasswordRecoveryOtpGenerator(static fn (): int => 4_271))->generate())
        ->toBe('004271');
});

test('production never falls back to the configured local demo OTP', function (): void {
    config()->set([
        'app.env' => 'production',
        'password_recovery.demo_code' => '123456',
    ]);

    expect((new PasswordRecoveryOtpGenerator(static fn (): int => 42))->generate())
        ->toBe('000042')
        ->not->toBe('123456');
});

test('an invalid demo OTP fails safely without exposing its value', function (): void {
    config()->set([
        'app.env' => 'local',
        'password_recovery.demo_code' => 'secret-value',
    ]);

    expect(fn (): string => (new PasswordRecoveryOtpGenerator)->generate())
        ->toThrow(LogicException::class, 'The local password recovery demo OTP configuration is invalid.');
});

test('production uses secure generation when demo OTP configuration is missing', function (): void {
    config()->set([
        'app.env' => 'production',
        'password_recovery.demo_code' => null,
    ]);

    expect((new PasswordRecoveryOtpGenerator(static fn (): int => 7))->generate())
        ->toBe('000007');
});
