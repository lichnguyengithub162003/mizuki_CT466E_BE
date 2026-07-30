<?php

namespace App\Services\Shipping;

use App\Exceptions\Shipping\GhnApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GhnClient
{
    private const MAX_SAFE_ATTEMPTS = 2;

    private string $baseUrl;

    private string $token;

    private string $shopId;

    private int $timeoutSeconds;

    private int $connectTimeoutSeconds;

    public function __construct()
    {
        $this->baseUrl = trim((string) config('services.ghn.base_url', ''));
        $this->token = trim((string) config('services.ghn.token', ''));
        $this->shopId = trim((string) config('services.ghn.shop_id', ''));
        $this->timeoutSeconds = max(1, (int) config('services.ghn.timeout_seconds', 10));
        $this->connectTimeoutSeconds = max(
            1,
            (int) config('services.ghn.connect_timeout_seconds', 5),
        );
    }

    /** @return list<array<string, mixed>> */
    public function provinces(): array
    {
        return $this->addressList(
            operation: 'provinces',
            method: 'GET',
            endpoint: 'master-data/province',
            requiredKeys: ['ProvinceID', 'ProvinceName'],
        );
    }

    /** @return list<array<string, mixed>> */
    public function districts(int $provinceId): array
    {
        return $this->addressList(
            operation: 'districts',
            method: 'POST',
            endpoint: 'master-data/district',
            payload: ['province_id' => $provinceId],
            requiredKeys: ['DistrictID', 'DistrictName'],
        );
    }

    /** @return list<array<string, mixed>> */
    public function wards(int $districtId): array
    {
        return $this->addressList(
            operation: 'wards',
            method: 'POST',
            endpoint: 'master-data/ward',
            payload: ['district_id' => $districtId],
            requiredKeys: ['WardCode', 'WardName'],
        );
    }

    /**
     * Reusable provider request foundation for later shipping operations.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function request(
        string $operation,
        string $method,
        string $endpoint,
        array $payload = [],
        bool $requiresShopId = false,
        bool $safeToRetry = false,
    ): array {
        $this->assertConfigured($operation, $requiresShopId);
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');
        $attempts = $safeToRetry ? self::MAX_SAFE_ATTEMPTS : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->send(
                    request: $this->pendingRequest($requiresShopId),
                    method: $method,
                    url: $url,
                    payload: $payload,
                );
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    continue;
                }

                throw new GhnApiException($operation, providerCode: 'connection_failure');
            }

            if ($response->serverError() && $attempt < $attempts) {
                continue;
            }

            try {
                $response->throw();
            } catch (RequestException) {
                throw new GhnApiException(
                    operation: $operation,
                    httpStatus: $response->status(),
                    providerCode: $this->extractProviderCode($response),
                );
            }

            return $this->validatedData($operation, $response);
        }

        throw new GhnApiException($operation, providerCode: 'request_failed');
    }

    protected function pendingRequest(bool $requiresShopId): PendingRequest
    {
        $headers = ['Token' => $this->token];

        if ($requiresShopId) {
            $headers['ShopId'] = $this->shopId;
        }

        return Http::acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(
        PendingRequest $request,
        string $method,
        string $url,
        array $payload,
    ): Response {
        $options = strtoupper($method) === 'GET'
            ? ['query' => $payload]
            : ['json' => $payload];

        return $request->send(strtoupper($method), $url, $options);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $requiredKeys
     * @return list<array<string, mixed>>
     */
    private function addressList(
        string $operation,
        string $method,
        string $endpoint,
        array $payload = [],
        array $requiredKeys = [],
    ): array {
        $data = $this->request(
            operation: $operation,
            method: $method,
            endpoint: $endpoint,
            payload: $payload,
            safeToRetry: true,
        );

        if (! array_is_list($data)) {
            throw new GhnApiException($operation, providerCode: 'malformed_data');
        }

        foreach ($data as $record) {
            if (! is_array($record)) {
                throw new GhnApiException($operation, providerCode: 'malformed_data');
            }

            foreach ($requiredKeys as $requiredKey) {
                if (! array_key_exists($requiredKey, $record)) {
                    throw new GhnApiException($operation, providerCode: 'malformed_data');
                }
            }
        }

        return $data;
    }

    /** @return array<array-key, mixed> */
    private function validatedData(string $operation, Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload) || array_is_list($payload)) {
            throw new GhnApiException(
                $operation,
                $response->status(),
                'malformed_response',
            );
        }

        $providerCode = $payload['code'] ?? null;
        $hasSuccessFlag = array_key_exists('success', $payload);
        $successFlag = $payload['success'] ?? null;
        $hasProviderCode = array_key_exists('code', $payload);
        $providerCodeIsSuccessful = $hasProviderCode && (string) $providerCode === '200';

        if (
            ($hasSuccessFlag && $successFlag !== true)
            || ($hasProviderCode && ! $providerCodeIsSuccessful)
        ) {
            throw new GhnApiException(
                $operation,
                $response->status(),
                $this->safeProviderCode($providerCode) ?? 'provider_failure',
            );
        }

        if ((! $hasSuccessFlag && ! $providerCodeIsSuccessful) || ! array_key_exists('data', $payload)) {
            throw new GhnApiException(
                $operation,
                $response->status(),
                'malformed_response',
            );
        }

        if (! is_array($payload['data'])) {
            throw new GhnApiException(
                $operation,
                $response->status(),
                'malformed_data',
            );
        }

        return $payload['data'];
    }

    private function extractProviderCode(Response $response): string|int|null
    {
        $payload = $response->json();

        return is_array($payload)
            ? $this->safeProviderCode($payload['code'] ?? null)
            : null;
    }

    private function safeProviderCode(mixed $providerCode): string|int|null
    {
        if (! is_scalar($providerCode)) {
            return null;
        }

        $value = (string) $providerCode;

        foreach ([$this->token, $this->shopId] as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                return 'provider_failure';
            }
        }

        return is_int($providerCode) ? $providerCode : $value;
    }

    private function assertConfigured(string $operation, bool $requiresShopId): void
    {
        if ($this->baseUrl === '' || $this->token === '') {
            throw new GhnApiException($operation, providerCode: 'configuration_missing');
        }

        if ($requiresShopId && $this->shopId === '') {
            throw new GhnApiException($operation, providerCode: 'shop_id_missing');
        }
    }
}
