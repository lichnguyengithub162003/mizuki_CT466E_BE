<?php

use App\Enums\UserRole;
use App\Mail\PasswordRecoveryOtpMail;
use App\Models\PasswordRecoveryChallenge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config()->set([
        'password_recovery.driver' => 'local',
        'password_recovery.demo_code' => '123456',
        'password_recovery.otp_ttl_minutes' => 5,
        'password_recovery.max_attempts' => 5,
        'password_recovery.resend_seconds' => 60,
        'password_recovery.verification_ttl_minutes' => 10,
    ]);
    Mail::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function passwordRecoveryCustomer(string $email = 'recovery@example.com'): User
{
    return User::factory()->create([
        'email' => $email,
        'password' => 'old-password',
        'role' => UserRole::Customer,
    ]);
}

function requestRecoveryOtp($test, string $email, string $ip = '10.10.0.1')
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
}

function verifyRecoveryOtp($test, string $email, string $code = '123456', string $ip = '10.20.0.1')
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/forgot-password/verify', [
            'email' => $email,
            'code' => $code,
        ]);
}

function issueRecoveryVerificationToken($test, User $user, string $ipSuffix = '1'): string
{
    requestRecoveryOtp($test, $user->email, "10.30.0.{$ipSuffix}")->assertOk();

    return verifyRecoveryOtp($test, $user->email, '123456', "10.40.0.{$ipSuffix}")
        ->assertOk()
        ->json('data.verification_token');
}

test('valid customer request sends a normalized email OTP without exposing or persisting plaintext', function (): void {
    $user = passwordRecoveryCustomer('customer@example.com');

    $response = requestRecoveryOtp($this, '  CUSTOMER@EXAMPLE.COM  ')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resend_after', 60)
        ->assertJsonPath('data.expires_in', 300)
        ->assertJsonPath('message', 'Mã xác thực đã được gửi đến email của bạn!')
        ->assertJsonPath('meta', []);

    $challenge = PasswordRecoveryChallenge::query()->sole();

    expect($challenge->user_id)->toBe($user->id)
        ->and($challenge->email)->toBe('customer@example.com')
        ->and($challenge->otp_hash)->not->toBe('123456')
        ->and(Hash::check('123456', $challenge->otp_hash))->toBeTrue()
        ->and($response->getContent())->not->toContain('123456')
        ->and($response->getContent())->not->toContain('otp_hash');

    Mail::assertSent(PasswordRecoveryOtpMail::class, function (PasswordRecoveryOtpMail $mail) use ($user): bool {
        return $mail->hasTo($user->email)
            && $mail->code === '123456'
            && $mail->expiresInMinutes === 5;
    });
});

test('OTP email contains Mizuki branding expiry and safety instructions', function (): void {
    $mail = new PasswordRecoveryOtpMail('123456', 5);
    $html = $mail->render();

    expect($html)->toContain('Mizuki')
        ->toContain('123456')
        ->toContain('5 phút')
        ->toContain('Không chia sẻ mã này')
        ->toContain('bỏ qua email');
});
test('unknown and staff emails receive the same enumeration safe response without mail or challenge', function (): void {
    $staff = User::factory()->create([
        'email' => 'staff@example.com',
        'role' => UserRole::Cashier,
    ]);

    $staffResponse = requestRecoveryOtp($this, strtoupper($staff->email), '10.10.1.1')->assertOk();
    $unknownResponse = requestRecoveryOtp($this, 'unknown@example.com', '10.10.1.2')->assertOk();

    expect($staffResponse->json())->toBe($unknownResponse->json());
    $this->assertDatabaseCount('password_recovery_challenges', 0);
    Mail::assertNothingSent();
});

test('unknown and staff emails receive the same enumeration safe resend cooldown', function (): void {
    User::factory()->create([
        'email' => 'staff@example.com',
        'role' => UserRole::Cashier,
    ]);

    requestRecoveryOtp($this, 'staff@example.com', '10.10.1.3')->assertOk();
    requestRecoveryOtp($this, 'unknown@example.com', '10.10.1.4')->assertOk();

    $staffResponse = requestRecoveryOtp($this, 'staff@example.com', '10.10.1.3')->assertUnprocessable();
    $unknownResponse = requestRecoveryOtp($this, 'unknown@example.com', '10.10.1.4')->assertUnprocessable();

    expect($staffResponse->json())->toBe($unknownResponse->json());
    $this->assertDatabaseCount('password_recovery_challenges', 0);
    Mail::assertNothingSent();
});
test('resend cooldown is enforced and a new OTP can be sent after cooldown', function (): void {
    $now = CarbonImmutable::parse('2026-07-31 10:00:00');
    CarbonImmutable::setTestNow($now);
    $user = passwordRecoveryCustomer();

    requestRecoveryOtp($this, $user->email, '10.10.2.1')->assertOk();
    requestRecoveryOtp($this, $user->email, '10.10.2.1')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.email.0', 'Vui lòng chờ 60 giây trước khi yêu cầu mã mới!');

    CarbonImmutable::setTestNow($now->addSeconds(61));
    requestRecoveryOtp($this, $user->email, '10.10.2.1')->assertOk();

    expect(PasswordRecoveryChallenge::query()->count())->toBe(2)
        ->and(PasswordRecoveryChallenge::query()->oldest('id')->first()->otp_consumed_at)->not->toBeNull();
    Mail::assertSentCount(2);
});

test('forgot password is throttled independently by email and IP', function (): void {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $response = requestRecoveryOtp($this, 'same-unknown@example.com', '10.10.3.1');

        if ($attempt === 1) {
            $response->assertOk();
        } else {
            $response->assertUnprocessable();
        }
    }

    requestRecoveryOtp($this, 'same-unknown@example.com', '10.10.3.1')
        ->assertTooManyRequests()
        ->assertJsonPath('success', false);

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        requestRecoveryOtp($this, "unknown-{$attempt}@example.com", '10.10.3.2')->assertOk();
    }

    requestRecoveryOtp($this, 'unknown-11@example.com', '10.10.3.2')
        ->assertTooManyRequests()
        ->assertJsonPath('success', false);
});

test('mail failure returns a safe error and invalidates the generated challenge', function (): void {
    $user = passwordRecoveryCustomer();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp-password-sensitive-detail'));

    $response = requestRecoveryOtp($this, $user->email, '10.10.4.1')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.email.0', 'Không thể gửi mã xác thực lúc này. Vui lòng thử lại sau!');

    expect($response->getContent())->not->toContain('smtp-password-sensitive-detail')
        ->and(PasswordRecoveryChallenge::query()->sole()->otp_consumed_at)->not->toBeNull();
});

test('valid OTP returns an opaque token and persists only its hash', function (): void {
    $user = passwordRecoveryCustomer();
    requestRecoveryOtp($this, $user->email)->assertOk();

    $response = verifyRecoveryOtp($this, strtoupper($user->email))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.expires_in', 600)
        ->assertJsonPath('message', 'Xác thực mã thành công!');
    $token = $response->json('data.verification_token');
    $challenge = PasswordRecoveryChallenge::query()->sole();

    expect($token)->toBeString()->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($challenge->verification_token_hash)->toBe(hash('sha256', $token))
        ->and($challenge->verification_token_hash)->not->toBe($token)
        ->and($challenge->otp_verified_at)->not->toBeNull()
        ->and($challenge->otp_consumed_at)->not->toBeNull()
        ->and($response->getContent())->not->toContain('verification_token_hash')
        ->and($response->getContent())->not->toContain('otp_hash');
});

test('wrong OTP increments failed attempts', function (): void {
    $user = passwordRecoveryCustomer();
    requestRecoveryOtp($this, $user->email)->assertOk();

    verifyRecoveryOtp($this, $user->email, '654321')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Mã xác thực không hợp lệ!');

    expect(PasswordRecoveryChallenge::query()->sole()->failed_attempts)->toBe(1);
});

test('OTP requires exactly six numeric digits', function (mixed $code): void {
    verifyRecoveryOtp($this, 'customer@example.com', $code, '10.20.1.1')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Mã xác thực phải gồm đúng 6 chữ số');
})->with([
    'too short' => '12345',
    'too long' => '1234567',
    'letters' => '12ab56',
    'integer coerced but short' => 12345,
]);

test('expired OTP is rejected and consumed', function (): void {
    $now = CarbonImmutable::parse('2026-07-31 10:00:00');
    CarbonImmutable::setTestNow($now);
    $user = passwordRecoveryCustomer();
    requestRecoveryOtp($this, $user->email)->assertOk();

    CarbonImmutable::setTestNow($now->addMinutes(6));
    verifyRecoveryOtp($this, $user->email)
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Mã xác thực đã hết hạn. Vui lòng yêu cầu mã mới!');

    expect(PasswordRecoveryChallenge::query()->sole()->otp_consumed_at)->not->toBeNull();
});

test('the fifth failed OTP attempt locks the challenge and even the correct code cannot reuse it', function (): void {
    $user = passwordRecoveryCustomer();
    requestRecoveryOtp($this, $user->email)->assertOk();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        verifyRecoveryOtp($this, $user->email, '654321', "10.20.2.{$attempt}")
            ->assertUnprocessable();
    }

    $challenge = PasswordRecoveryChallenge::query()->sole();
    expect($challenge->failed_attempts)->toBe(5)
        ->and($challenge->otp_consumed_at)->not->toBeNull();

    verifyRecoveryOtp($this, $user->email, '123456', '10.20.2.6')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Mã xác thực không hợp lệ!');
});

test('a successfully verified OTP cannot be verified again', function (): void {
    $user = passwordRecoveryCustomer();
    issueRecoveryVerificationToken($this, $user, '3');

    verifyRecoveryOtp($this, $user->email, '123456', '10.20.3.2')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Mã xác thực không hợp lệ!');
});

test('valid verification token resets password atomically without authenticating user', function (): void {
    $user = passwordRecoveryCustomer();
    $token = issueRecoveryVerificationToken($this, $user, '4');

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => strtoupper($user->email),
        'verification_token' => $token,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', [])
        ->assertJsonPath('message', 'Đặt lại mật khẩu thành công!');

    $challenge = PasswordRecoveryChallenge::query()->sole();
    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue()
        ->and($challenge->refresh()->token_consumed_at)->not->toBeNull();
    $this->assertGuest();
});

test('old password fails and new password succeeds after reset', function (): void {
    $user = passwordRecoveryCustomer();
    $token = issueRecoveryVerificationToken($this, $user, '5');

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'verification_token' => $token,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'old-password',
    ])->assertUnauthorized();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'new-password',
    ])->assertOk()->assertJsonPath('data.id', $user->id);
});

test('verification token is bound to the same customer email', function (): void {
    $user = passwordRecoveryCustomer('first@example.com');
    $other = passwordRecoveryCustomer('second@example.com');
    $token = issueRecoveryVerificationToken($this, $user, '6');

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $other->email,
        'verification_token' => $token,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.verification_token.0', 'Phiên xác thực không hợp lệ hoặc đã được sử dụng!');

    expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue()
        ->and(PasswordRecoveryChallenge::query()->sole()->token_consumed_at)->toBeNull();
});

test('expired verification token is rejected and consumed', function (): void {
    $now = CarbonImmutable::parse('2026-07-31 10:00:00');
    CarbonImmutable::setTestNow($now);
    $user = passwordRecoveryCustomer();
    $token = issueRecoveryVerificationToken($this, $user, '7');

    CarbonImmutable::setTestNow($now->addMinutes(11));
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'verification_token' => $token,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.verification_token.0', 'Phiên xác thực đã hết hạn. Vui lòng yêu cầu mã mới!');

    expect(PasswordRecoveryChallenge::query()->sole()->token_consumed_at)->not->toBeNull()
        ->and(Hash::check('old-password', $user->refresh()->password))->toBeTrue();
});

test('used verification token cannot reset password twice', function (): void {
    $user = passwordRecoveryCustomer();
    $token = issueRecoveryVerificationToken($this, $user, '8');
    $payload = [
        'email' => $user->email,
        'verification_token' => $token,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ];

    $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/v1/auth/reset-password', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.verification_token.0', 'Phiên xác thực không hợp lệ hoặc đã được sử dụng!');
});

test('reset validation enforces confirmation and minimum password length', function (array $payload, string $message): void {
    $this->postJson('/api/v1/auth/reset-password', array_merge([
        'email' => 'customer@example.com',
        'verification_token' => str_repeat('a', 64),
    ], $payload))->assertUnprocessable()->assertJsonPath('data.errors.password.0', $message);
})->with([
    'confirmation mismatch' => [[
        'password' => 'new-password',
        'password_confirmation' => 'different-password',
    ], 'Xác nhận mật khẩu không khớp'],
    'minimum length' => [[
        'password' => 'short',
        'password_confirmation' => 'short',
    ], 'Mật khẩu tối thiểu 8 ký tự'],
]);
