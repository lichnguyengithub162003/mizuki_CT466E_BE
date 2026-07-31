<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class GoogleOAuthException extends RuntimeException
{
    public const CANCELLED = 'google_cancelled';

    public const INVALID_CALLBACK = 'google_invalid_callback';

    public const UNVERIFIED_EMAIL = 'google_unverified_email';

    public const STAFF_ACCOUNT = 'google_staff_account';

    public const AUTH_FAILED = 'google_auth_failed';

    public function __construct(
        public readonly string $safeCode,
    ) {
        parent::__construct('Google OAuth authentication failed.');
    }
}
