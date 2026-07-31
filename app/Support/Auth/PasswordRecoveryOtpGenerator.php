<?php

namespace App\Support\Auth;

use Closure;
use LogicException;

final class PasswordRecoveryOtpGenerator
{
    /** @param null|Closure(): int $randomNumberGenerator */
    public function __construct(
        private readonly ?Closure $randomNumberGenerator = null,
    ) {}

    public function generate(): string
    {
        if ($this->demoOtpIsAllowed()) {
            $demoCode = trim((string) config('password_recovery.demo_code', ''));

            if (preg_match('/\A[0-9]{6}\z/', $demoCode) !== 1) {
                throw new LogicException('The local password recovery demo OTP configuration is invalid.');
            }

            return $demoCode;
        }

        $number = $this->randomNumberGenerator === null
            ? random_int(0, 999_999)
            : ($this->randomNumberGenerator)();

        if ($number < 0 || $number > 999_999) {
            throw new LogicException('The password recovery OTP generator returned an invalid value.');
        }

        return sprintf('%06d', $number);
    }

    private function demoOtpIsAllowed(): bool
    {
        return in_array((string) config('app.env', 'production'), ['local', 'testing'], true);
    }
}
