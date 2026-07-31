<?php

use App\Exceptions\Shipping\GhnApiException;
use App\Services\Shipping\GhnAddressService;
use App\Services\Shipping\GhnClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class InspectableGhnClientForTest extends GhnClient
{
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    /** @return array<array-key, mixed> */
    public function requestWithShopId(): array
    {
        return $this->request(
            operation: 'shop_test',
            method: 'POST',
            endpoint: '/shop-test',
            requiresShopId: true,
        );
    }

    /** @return array<array-key, mixed> */
    public function requestEndpoint(string $endpoint): array
    {
        return $this->request(
            operation: 'endpoint_test',
            method: 'GET',
            endpoint: $endpoint,
        );
    }

    protected function pendingRequest(bool $requiresShopId): PendingRequest
    {
        return parent::pendingRequest($requiresShopId)
            ->beforeSending(function (Request $request, array $options): void {
                $this->lastOptions = $options;
            });
    }
}

beforeEach(function (): void {
    config()->set([
        'services.ghn.base_url' => 'https://ghn.test/api',
        'services.ghn.token' => 'test-secret-token',
        'services.ghn.shop_id' => '123456',
        'services.ghn.timeout_seconds' => 12,
        'services.ghn.connect_timeout_seconds' => 4,
    ]);
    Cache::flush();
    Http::preventStrayRequests();
});

function captureGhnException(Closure $callback): GhnApiException
{
    try {
        $callback();
    } catch (GhnApiException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a GhnApiException to be thrown.');
}

function ghnSuccess(array $data): array
{
    return ['code' => 200, 'message' => 'Success', 'data' => $data];
}

test('missing required configuration fails before an HTTP request', function (string $key): void {
    config()->set("services.ghn.{$key}", '');
    Http::fake();

    $exception = captureGhnException(fn (): array => app(GhnClient::class)->provinces());

    expect($exception->operation)->toBe('provinces')
        ->and($exception->providerCode)->toBe('configuration_missing')
        ->and($exception->getMessage())->not->toContain('test-secret-token');
    Http::assertNothingSent();
})->with(['base_url', 'token']);

test('normalizes URL slashes and extracts successful response data', function (): void {
    config()->set('services.ghn.base_url', 'https://ghn.test/api///');
    Http::fake([
        'https://ghn.test/api/master-data/province' => Http::response(ghnSuccess([
            ['ProvinceID' => 1, 'ProvinceName' => 'Cần Thơ'],
        ])),
    ]);

    $result = (new InspectableGhnClientForTest)->requestEndpoint('/master-data/province');

    expect($result)->toBe([
        ['ProvinceID' => 1, 'ProvinceName' => 'Cần Thơ'],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://ghn.test/api/master-data/province');
});

test('sends token and JSON headers while omitting ShopId by default', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([]))]);

    app(GhnClient::class)->provinces();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Token', 'test-secret-token')
        && ! $request->hasHeader('ShopId')
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Content-Type', 'application/json'));
});

test('sends ShopId only when an operation explicitly requires it', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([]))]);

    (new InspectableGhnClientForTest)->requestWithShopId();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Token', 'test-secret-token')
        && $request->hasHeader('ShopId', '123456'));
});

test('rejects a missing ShopId before an operation that requires it', function (): void {
    config()->set('services.ghn.shop_id', '');
    Http::fake();

    $exception = captureGhnException(
        fn (): array => (new InspectableGhnClientForTest)->requestWithShopId(),
    );

    expect($exception->providerCode)->toBe('shop_id_missing')
        ->and($exception->getMessage())->not->toContain('test-secret-token');
    Http::assertNothingSent();
});

test('applies configured request and connection timeouts', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([]))]);
    $client = new InspectableGhnClientForTest;

    $client->requestEndpoint('master-data/province');

    expect($client->lastOptions['timeout'])->toBe(12)
        ->and($client->lastOptions['connect_timeout'])->toBe(4);
});

test('sends district and ward identifiers as JSON request bodies', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([]))]);
    $client = app(GhnClient::class);

    $client->districts(91);
    $client->wards(916);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ghn.test/api/master-data/district'
        && $request['province_id'] === 91);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ghn.test/api/master-data/ward'
        && $request['district_id'] === 916);
});

test('normalizes HTTP client failures without retrying them', function (): void {
    Http::fake(['*' => Http::response([
        'code' => 'INVALID_TOKEN',
        'message' => 'Rejected test-secret-token',
    ], 401)]);

    $exception = captureGhnException(fn (): array => app(GhnClient::class)->provinces());

    expect($exception->httpStatus)->toBe(401)
        ->and($exception->providerCode)->toBe('INVALID_TOKEN')
        ->and($exception->getMessage())->not->toContain('test-secret-token')
        ->and($exception->getMessage())->not->toContain('Rejected');
    Http::assertSentCount(1);
});

test('retries safe server failures then normalizes the final response', function (): void {
    Http::fake(['*' => Http::response([
        'code' => 'SERVER_ERROR',
        'message' => 'Internal test-secret-token',
    ], 503)]);

    $exception = captureGhnException(fn (): array => app(GhnClient::class)->provinces());

    expect($exception->httpStatus)->toBe(503)
        ->and($exception->providerCode)->toBe('SERVER_ERROR')
        ->and($exception->getMessage())->not->toContain('test-secret-token');
    Http::assertSentCount(2);
});

test('retries safe connection failures and exposes only safe context', function (): void {
    Http::fake(['*' => Http::failedConnection('Connection failed with test-secret-token')]);

    $exception = captureGhnException(fn (): array => app(GhnClient::class)->provinces());

    expect($exception->httpStatus)->toBeNull()
        ->and($exception->providerCode)->toBe('connection_failure')
        ->and($exception->getMessage())->not->toContain('test-secret-token')
        ->and($exception->getMessage())->not->toContain('Connection failed');
    Http::assertSentCount(2);
});

test('rejects malformed provider payloads', function (mixed $payload, string $expectedCode): void {
    Http::fake(['*' => Http::response($payload, 200)]);

    $exception = captureGhnException(fn (): array => app(GhnClient::class)->provinces());

    expect($exception->providerCode)->toBe($expectedCode)
        ->and($exception->getMessage())->not->toContain('test-secret-token');
})->with([
    'invalid JSON root' => ['not-json', 'malformed_response'],
    'missing provider success marker' => [['data' => []], 'malformed_response'],
    'non-array data' => [['code' => 200, 'data' => 'invalid'], 'malformed_data'],
    'non-list address data' => [['code' => 200, 'data' => ['id' => 1]], 'malformed_data'],
    'missing address keys' => [['code' => 200, 'data' => [[]]], 'malformed_data'],
]);

test('rejects a provider-level failure returned with HTTP 200', function (): void {
    Http::fake(['*' => Http::response([
        'success' => false,
        'code' => 'INVALID_DATA',
        'message' => 'Bad test-secret-token',
        'data' => [],
    ])]);

    $exception = captureGhnException(fn (): array => app(GhnClient::class)->provinces());

    expect($exception->httpStatus)->toBe(200)
        ->and($exception->providerCode)->toBe('INVALID_DATA')
        ->and($exception->getMessage())->not->toContain('test-secret-token')
        ->and($exception->getMessage())->not->toContain('Bad');
});

test('successful province responses are cached for subsequent calls', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([
        ['ProvinceID' => 91, 'ProvinceName' => 'Cần Thơ'],
    ]))]);
    $service = app(GhnAddressService::class);

    $first = $service->getProvinces();
    $second = $service->getProvinces();

    expect($second)->toBe($first)
        ->and(Cache::has('ghn.provinces'))->toBeTrue();
    Http::assertSentCount(1);
});

test('district caches are isolated by province ID', function (): void {
    Http::fake(function (Request $request) {
        $provinceId = $request['province_id'];

        return Http::response(ghnSuccess([
            ['DistrictID' => $provinceId * 10, 'DistrictName' => "District {$provinceId}"],
        ]));
    });
    $service = app(GhnAddressService::class);

    $first = $service->getDistricts(91);
    $second = $service->getDistricts(92);
    $service->getDistricts(91);

    expect($first)->not->toBe($second)
        ->and(Cache::has('ghn.districts.91'))->toBeTrue()
        ->and(Cache::has('ghn.districts.92'))->toBeTrue();
    Http::assertSentCount(2);
});

test('ward caches are isolated by district ID', function (): void {
    Http::fake(function (Request $request) {
        $districtId = $request['district_id'];

        return Http::response(ghnSuccess([
            ['WardCode' => (string) $districtId, 'WardName' => "Ward {$districtId}"],
        ]));
    });
    $service = app(GhnAddressService::class);

    $first = $service->getWards(916);
    $second = $service->getWards(917);
    $service->getWards(916);

    expect($first)->not->toBe($second)
        ->and(Cache::has('ghn.wards.916'))->toBeTrue()
        ->and(Cache::has('ghn.wards.917'))->toBeTrue();
    Http::assertSentCount(2);
});

test('failed address responses are never cached', function (): void {
    Http::fake(['*' => Http::response(['code' => 'DOWN'], 503)]);
    $service = app(GhnAddressService::class);

    captureGhnException(fn (): array => $service->getProvinces());
    captureGhnException(fn (): array => $service->getProvinces());

    expect(Cache::has('ghn.provinces'))->toBeFalse();
    Http::assertSentCount(4);
});

test('malformed address responses are never cached', function (): void {
    Http::fake(['*' => Http::response(['code' => 200, 'data' => 'invalid'])]);
    $service = app(GhnAddressService::class);

    captureGhnException(fn (): array => $service->getProvinces());
    captureGhnException(fn (): array => $service->getProvinces());

    expect(Cache::has('ghn.provinces'))->toBeFalse();
    Http::assertSentCount(2);
});

test('public location response envelope and field mapping remain unchanged', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([
        ['ProvinceID' => 91, 'ProvinceName' => 'Cần Thơ'],
    ]))]);

    $this->getJson('/api/v1/locations/provinces')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.ghn_province_id', 91)
        ->assertJsonPath('data.0.name', 'Cần Thơ')
        ->assertJsonPath('meta', []);
});
test('redacts provider codes that contain configured secrets', function (): void {
    Http::fake(['*' => Http::response([
        'success' => false,
        'code' => 'test-secret-token',
        'data' => [],
    ])]);

    $exception = captureGhnException(fn (): array => app(GhnClient::class)->provinces());

    expect($exception->providerCode)->toBe('provider_failure')
        ->and($exception->getMessage())->not->toContain('test-secret-token')
        ->and($exception->getMessage())->not->toContain('123456');
});

test('a safe server failure is retried and can recover', function (): void {
    Http::fakeSequence()
        ->push(['code' => 'TEMPORARY'], 503)
        ->push(ghnSuccess([
            ['ProvinceID' => 91, 'ProvinceName' => 'Cần Thơ'],
        ]), 200);

    $result = app(GhnClient::class)->provinces();

    expect($result)->toBe([
        ['ProvinceID' => 91, 'ProvinceName' => 'Cần Thơ'],
    ]);
    Http::assertSentCount(2);
});

test('the GHN exception itself redacts configured secrets from provider context', function (string $secret): void {
    $exception = new GhnApiException('direct_test', 400, "PREFIX-{$secret}");

    expect($exception->providerCode)->toBe('provider_failure')
        ->and($exception->getMessage())->not->toContain($secret);
})->with([
    'token' => ['test-secret-token'],
    'shop ID' => ['123456'],
]);

test('available services sends the documented route payload without a ShopId header', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([
        ['service_id' => 53320, 'short_name' => 'Hàng nhẹ', 'service_type_id' => 2],
    ]))]);

    $services = app(GhnClient::class)->availableServices(885, 1447, 1442);

    expect($services[0]['service_id'])->toBe(53320);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ghn.test/api/v2/shipping-order/available-services'
        && $request['shop_id'] === 885
        && $request['from_district'] === 1447
        && $request['to_district'] === 1442
        && ! $request->hasHeader('ShopId'));
});

test('shipping fee sends ShopId and extracts validated numeric fee fields', function (): void {
    Http::fake(['*' => Http::response(ghnSuccess([
        'total' => 36_300,
        'service_fee' => 35_000,
        'insurance_fee' => 1_300,
    ]))]);
    $payload = [
        'service_id' => 53320,
        'to_district_id' => 1442,
        'to_ward_code' => '21012',
        'weight' => 500,
    ];

    $fee = app(GhnClient::class)->calculateShippingFee($payload);

    expect($fee['total'])->toBe(36_300)
        ->and($fee['service_fee'])->toBe(35_000)
        ->and($fee['expected_delivery_time'])->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ghn.test/api/v2/shipping-order/fee'
        && $request->hasHeader('ShopId', '123456')
        && $request['service_id'] === 53320
        && $request['weight'] === 500);
});

test('shipping operations reject malformed service and fee data safely', function (string $operation): void {
    Http::fake(['*' => Http::response(ghnSuccess(
        $operation === 'services'
            ? [['service_id' => 'invalid', 'short_name' => 'Bad', 'service_type_id' => 2]]
            : ['total' => 'invalid'],
    ))]);

    $exception = captureGhnException(fn (): array => $operation === 'services'
        ? app(GhnClient::class)->availableServices(885, 1447, 1442)
        : app(GhnClient::class)->calculateShippingFee(['weight' => 500]));

    expect($exception->providerCode)->toBe('malformed_data')
        ->and($exception->getMessage())->not->toContain('test-secret-token')
        ->and($exception->getMessage())->not->toContain('123456');
})->with(['services', 'fee']);
