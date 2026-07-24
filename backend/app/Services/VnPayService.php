<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class VnPayService extends BaseService
{
    /**
     * @return array{payment_url: string, expires_at: string, payment_number: string}
     */
    public function createPaymentUrl(Payment $payment, Order $order, string $ipAddress): array
    {
        $this->ensureConfigured();

        $timezone = (string) config('vnpay.timezone', 'Asia/Ho_Chi_Minh');
        $createdAt = CarbonImmutable::now($timezone);
        $expiresAt = $createdAt->addMinutes((int) config('vnpay.expire_minutes', 15));

        $params = [
            'vnp_Version' => (string) config('vnpay.version'),
            'vnp_Command' => (string) config('vnpay.command'),
            'vnp_TmnCode' => (string) config('vnpay.tmn_code'),
            'vnp_Amount' => (string) ($payment->amount * 100),
            'vnp_CurrCode' => (string) config('vnpay.currency'),
            'vnp_TxnRef' => $payment->payment_number,
            'vnp_OrderInfo' => "Thanh toan don hang {$order->order_number}",
            'vnp_OrderType' => (string) config('vnpay.order_type'),
            'vnp_Locale' => (string) config('vnpay.locale'),
            'vnp_ReturnUrl' => (string) config('vnpay.return_url'),
            'vnp_IpAddr' => $this->normalizeIpAddress($ipAddress),
            'vnp_CreateDate' => $createdAt->format('YmdHis'),
            'vnp_ExpireDate' => $expiresAt->format('YmdHis'),
        ];

        $query = $this->canonicalize($params);
        $secureHash = $this->generateSecureHash($params);

        return [
            'payment_url' => rtrim((string) config('vnpay.payment_url'), '?')
                .'?'.$query.'&vnp_SecureHash='.$secureHash,
            'expires_at' => $expiresAt->toIso8601String(),
            'payment_number' => $payment->payment_number,
        ];
    }

    /** @param array<string, mixed> $params */
    public function verifySignature(array $params): bool
    {
        $providedHash = (string) ($params['vnp_SecureHash'] ?? '');

        if ($providedHash === '' || (string) config('vnpay.hash_secret') === '') {
            return false;
        }

        return hash_equals(
            strtolower($this->generateSecureHash($params)),
            strtolower($providedHash),
        );
    }

    /** @param array<string, mixed> $params */
    public function generateSecureHash(array $params): string
    {
        return hash_hmac(
            'sha512',
            $this->canonicalize($params),
            (string) config('vnpay.hash_secret'),
        );
    }

    /** @param array<string, mixed> $params */
    public function isSuccessful(array $params): bool
    {
        return ($params['vnp_ResponseCode'] ?? null) === '00'
            && ($params['vnp_TransactionStatus'] ?? null) === '00';
    }

    /** @param array<string, mixed> $params */
    public function hasValidCallbackShape(array $params): bool
    {
        foreach ([
            'vnp_TmnCode',
            'vnp_Amount',
            'vnp_TxnRef',
            'vnp_ResponseCode',
            'vnp_TransactionStatus',
        ] as $key) {
            if (! isset($params[$key]) || ! is_scalar($params[$key]) || (string) $params[$key] === '') {
                return false;
            }
        }

        return hash_equals(
            (string) config('vnpay.tmn_code'),
            (string) $params['vnp_TmnCode'],
        );
    }

    /** @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function sanitizeCallback(array $params): array
    {
        $allowedKeys = [
            'vnp_Amount',
            'vnp_BankCode',
            'vnp_BankTranNo',
            'vnp_CardType',
            'vnp_OrderInfo',
            'vnp_PayDate',
            'vnp_ResponseCode',
            'vnp_TmnCode',
            'vnp_TransactionNo',
            'vnp_TransactionStatus',
            'vnp_TxnRef',
        ];

        $sanitized = [];

        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $params) && is_scalar($params[$key])) {
                $sanitized[$key] = (string) $params[$key];
            }
        }

        return $sanitized;
    }

    /** @param array<string, mixed> $params */
    private function canonicalize(array $params): string
    {
        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);

        $params = array_filter(
            $params,
            static fn (mixed $value, string $key): bool => str_starts_with($key, 'vnp_')
                && is_scalar($value)
                && (string) $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        ksort($params);

        return implode('&', array_map(
            static fn (string $key, mixed $value): string => urlencode($key).'='.urlencode((string) $value),
            array_keys($params),
            array_values($params),
        ));
    }

    private function normalizeIpAddress(string $ipAddress): string
    {
        if ($ipAddress === '::1') {
            return '127.0.0.1';
        }

        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
            ? $ipAddress
            : '127.0.0.1';
    }

    private function ensureConfigured(): void
    {
        foreach (['tmn_code', 'hash_secret', 'payment_url', 'return_url'] as $key) {
            if (trim((string) config("vnpay.{$key}")) === '') {
                throw new InvalidArgumentException('Cấu hình VNPay chưa đầy đủ');
            }
        }
    }
}
