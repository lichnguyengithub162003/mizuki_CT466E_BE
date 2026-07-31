<?php

return [
    'driver' => env('PASSWORD_RECOVERY_DRIVER', 'local'),
    'demo_code' => env('PASSWORD_RECOVERY_DEMO_CODE', '123456'),
    'otp_ttl_minutes' => (int) env('PASSWORD_RECOVERY_OTP_TTL_MINUTES', 5),
    'max_attempts' => (int) env('PASSWORD_RECOVERY_MAX_ATTEMPTS', 5),
    'resend_seconds' => (int) env('PASSWORD_RECOVERY_RESEND_SECONDS', 60),
    'verification_ttl_minutes' => (int) env('PASSWORD_RECOVERY_VERIFICATION_TTL_MINUTES', 10),
];
