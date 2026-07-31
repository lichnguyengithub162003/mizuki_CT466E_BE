<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Mail\PasswordRecoveryOtpMail;
use App\Models\PasswordRecoveryChallenge;
use App\Repositories\PasswordRecoveryChallengeRepository;
use App\Repositories\UserRepository;
use App\Services\BaseService;
use App\Support\Auth\PasswordRecoveryOtpGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordRecoveryService extends BaseService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordRecoveryChallengeRepository $challenges,
        private readonly PasswordRecoveryOtpGenerator $otpGenerator,
    ) {}

    /** @return array{resend_after: int, expires_in: int} */
    public function requestOtp(string $email): array
    {
        $email = $this->normalizeEmail($email);
        $resendSeconds = $this->resendSeconds();
        $otpTtlMinutes = $this->otpTtlMinutes();
        $publicResult = [
            'resend_after' => $resendSeconds,
            'expires_in' => $otpTtlMinutes * 60,
        ];
        $cooldownKey = $this->cooldownKey($email);
        $cooldownUntil = now()->addSeconds($resendSeconds);

        // Apply the same opaque cooldown to every email to prevent enumeration.
        if (! Cache::add($cooldownKey, $cooldownUntil->getTimestamp(), $cooldownUntil)) {
            $this->cooldownError($cooldownKey);
        }

        $user = $this->users->findByEmail($email);

        // Unknown and internal accounts receive the same public response.
        if ($user === null || $user->role !== UserRole::Customer) {
            return $publicResult;
        }

        $code = $this->otpGenerator->generate();
        $result = $this->challenges->transaction(function () use (
            $user,
            $email,
            $code,
            $resendSeconds,
            $otpTtlMinutes,
        ): array {
            $lockedUser = $this->users->lockById($user->id);

            if ($lockedUser === null || $lockedUser->role !== UserRole::Customer) {
                return ['challenge' => null, 'retry_after' => null];
            }

            $latest = $this->challenges->latestForUserLocked($lockedUser->id, $email);

            if ($latest !== null && $latest->resend_available_at->isFuture()) {
                return [
                    'challenge' => null,
                    'retry_after' => max(
                        1,
                        $latest->resend_available_at->getTimestamp() - now()->getTimestamp(),
                    ),
                ];
            }

            $this->challenges->invalidateAllForUser($lockedUser->id);

            return [
                'challenge' => $this->challenges->createChallenge([
                    'user_id' => $lockedUser->id,
                    'email' => $email,
                    'otp_hash' => Hash::make($code),
                    'otp_expires_at' => now()->addMinutes($otpTtlMinutes),
                    'failed_attempts' => 0,
                    'resend_available_at' => now()->addSeconds($resendSeconds),
                ]),
                'retry_after' => null,
            ];
        });

        if (is_int($result['retry_after'])) {
            $availableAt = now()->addSeconds($result['retry_after']);
            Cache::put($cooldownKey, $availableAt->getTimestamp(), $availableAt);
            $this->cooldownError($cooldownKey);
        }

        $challenge = $result['challenge'];

        if (! $challenge instanceof PasswordRecoveryChallenge) {
            return $publicResult;
        }

        try {
            Mail::to($user->email)->send(new PasswordRecoveryOtpMail($code, $otpTtlMinutes));
        } catch (Throwable) {
            $this->invalidateAfterMailFailure($challenge->id);
            Cache::forget($cooldownKey);

            throw ValidationException::withMessages([
                'email' => ['Không thể gửi mã xác thực lúc này. Vui lòng thử lại sau!'],
            ]);
        }

        return $publicResult;
    }

    /** @return array{verification_token: string, expires_in: int} */
    public function verifyOtp(string $email, string $code): array
    {
        $email = $this->normalizeEmail($email);
        $verificationTtlMinutes = $this->verificationTtlMinutes();
        $user = $this->users->findByEmail($email);

        if ($user === null || $user->role !== UserRole::Customer) {
            $this->invalidCode();
        }

        $result = $this->challenges->transaction(function () use (
            $user,
            $email,
            $code,
            $verificationTtlMinutes,
        ): array {
            $lockedUser = $this->users->lockById($user->id);
            $challenge = $lockedUser === null
                ? null
                : $this->challenges->latestForUserLocked($lockedUser->id, $email);

            if ($challenge === null
                || $challenge->otp_consumed_at !== null
                || $challenge->failed_attempts >= $this->maxAttempts()) {
                return ['status' => 'invalid'];
            }

            if ($challenge->otp_expires_at->lessThanOrEqualTo(now())) {
                $this->challenges->updateChallenge($challenge, ['otp_consumed_at' => now()]);

                return ['status' => 'expired'];
            }

            if (! Hash::check($code, $challenge->otp_hash)) {
                $failedAttempts = $challenge->failed_attempts + 1;
                $attributes = ['failed_attempts' => $failedAttempts];

                if ($failedAttempts >= $this->maxAttempts()) {
                    $attributes['otp_consumed_at'] = now();
                }

                $this->challenges->updateChallenge($challenge, $attributes);

                return ['status' => 'invalid'];
            }

            $verificationToken = bin2hex(random_bytes(32));
            $this->challenges->updateChallenge($challenge, [
                'otp_verified_at' => now(),
                'otp_consumed_at' => now(),
                'verification_token_hash' => hash('sha256', $verificationToken),
                'verification_expires_at' => now()->addMinutes($verificationTtlMinutes),
            ]);

            return [
                'status' => 'verified',
                'verification_token' => $verificationToken,
            ];
        });

        if ($result['status'] === 'expired') {
            throw ValidationException::withMessages([
                'code' => ['Mã xác thực đã hết hạn. Vui lòng yêu cầu mã mới!'],
            ]);
        }

        if ($result['status'] !== 'verified') {
            $this->invalidCode();
        }

        return [
            'verification_token' => $result['verification_token'],
            'expires_in' => $verificationTtlMinutes * 60,
        ];
    }

    /** @param array{email: string, verification_token: string, password: string} $data */
    public function resetPassword(array $data): void
    {
        $email = $this->normalizeEmail($data['email']);
        $tokenHash = hash('sha256', $data['verification_token']);

        $status = $this->challenges->transaction(function () use ($email, $tokenHash, $data): string {
            $user = $this->users->lockCustomerByEmail($email);
            $challenge = $user === null
                ? null
                : $this->challenges->latestForUserLocked($user->id, $email);

            if ($user === null
                || $challenge === null
                || $challenge->otp_verified_at === null
                || $challenge->verification_token_hash === null
                || $challenge->verification_expires_at === null
                || $challenge->token_consumed_at !== null
                || ! hash_equals($challenge->verification_token_hash, $tokenHash)) {
                return 'invalid';
            }

            if ($challenge->verification_expires_at->lessThanOrEqualTo(now())) {
                $this->challenges->updateChallenge($challenge, ['token_consumed_at' => now()]);

                return 'expired';
            }

            $this->users->updateRecoveredPassword($user, $data['password']);
            $this->challenges->invalidateAllForUser($user->id);

            return 'reset';
        });

        if ($status === 'expired') {
            throw ValidationException::withMessages([
                'verification_token' => ['Phiên xác thực đã hết hạn. Vui lòng yêu cầu mã mới!'],
            ]);
        }

        if ($status !== 'reset') {
            throw ValidationException::withMessages([
                'verification_token' => ['Phiên xác thực không hợp lệ hoặc đã được sử dụng!'],
            ]);
        }
    }

    private function invalidateAfterMailFailure(int $challengeId): void
    {
        $this->challenges->transaction(function () use ($challengeId): void {
            $challenge = $this->challenges->findLocked($challengeId);

            if ($challenge !== null) {
                $this->challenges->updateChallenge($challenge, [
                    'otp_consumed_at' => now(),
                    'token_consumed_at' => now(),
                    'resend_available_at' => now(),
                ]);
            }
        });
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function otpTtlMinutes(): int
    {
        return max(1, (int) config('password_recovery.otp_ttl_minutes', 5));
    }

    private function verificationTtlMinutes(): int
    {
        return max(1, (int) config('password_recovery.verification_ttl_minutes', 10));
    }

    private function resendSeconds(): int
    {
        return max(1, (int) config('password_recovery.resend_seconds', 60));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('password_recovery.max_attempts', 5));
    }

    private function cooldownKey(string $email): string
    {
        return 'password-recovery:resend:'.hash('sha256', $email);
    }

    private function cooldownError(string $cooldownKey): never
    {
        $defaultAvailableAt = now()->addSeconds($this->resendSeconds())->getTimestamp();
        $availableAt = (int) Cache::get($cooldownKey, $defaultAvailableAt);
        $retryAfter = max(1, $availableAt - now()->getTimestamp());

        throw ValidationException::withMessages([
            'email' => ["Vui lòng chờ {$retryAfter} giây trước khi yêu cầu mã mới!"],
        ]);
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages([
            'code' => ['Mã xác thực không hợp lệ!'],
        ]);
    }
}
