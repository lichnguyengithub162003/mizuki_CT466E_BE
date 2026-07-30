<?php

namespace App\Exceptions\Shipping;

use RuntimeException;

class GhnApiException extends RuntimeException
{
    public readonly ?string $providerCode;

    public function __construct(
        public readonly string $operation,
        public readonly ?int $httpStatus = null,
        string|int|null $providerCode = null,
    ) {
        $this->providerCode = $this->sanitizeProviderCode($providerCode);
        $context = ["GHN operation [{$operation}] failed."];

        if ($httpStatus !== null) {
            $context[] = "HTTP status: {$httpStatus}.";
        }

        if ($this->providerCode !== null) {
            $context[] = "Provider code: {$this->providerCode}.";
        }

        parent::__construct(implode(' ', $context));
    }

    private function sanitizeProviderCode(string|int|null $providerCode): ?string
    {
        if ($providerCode === null) {
            return null;
        }

        $rawCode = (string) $providerCode;

        foreach ([config('services.ghn.token'), config('services.ghn.shop_id')] as $secret) {
            $secret = trim((string) $secret);

            if ($secret !== '' && str_contains($rawCode, $secret)) {
                return 'provider_failure';
            }
        }

        $safeCode = preg_replace('/[^A-Za-z0-9_.:-]/', '', $rawCode);
        $safeCode = mb_substr((string) $safeCode, 0, 64);

        return $safeCode === '' ? null : $safeCode;
    }
}
