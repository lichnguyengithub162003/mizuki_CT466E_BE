<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Repositories\RefundRepository;
use App\Services\Media\MediaObject;
use App\Services\Media\MediaVisibility;
use App\Services\Media\PrivateFileServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

/** @return array{customer: User, other_customer: User, branch: Branch, other_branch: Branch, order: Order} */
function createRefundEvidenceContext(): array
{
    $token = Str::upper(Str::random(8));
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
    $branch = createRefundEvidenceBranch('EV'.$token);
    $otherBranch = createRefundEvidenceBranch('OX'.$token);
    $order = Order::query()->create([
        'order_number' => 'MZ-EV-'.$token,
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Delivered,
        'subtotal' => 300_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 300_000,
        'placed_at' => now(),
    ]);

    return compact('customer', 'otherCustomer', 'branch', 'otherBranch', 'order') + [
        'other_customer' => $otherCustomer,
        'other_branch' => $otherBranch,
    ];
}

function createRefundEvidenceBranch(string $code): Branch
{
    return Branch::query()->create([
        'code' => $code,
        'name' => 'Mizuki Evidence '.$code,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
}

function createRefundEvidenceRefund(array $context, array $paths): Refund
{
    return Refund::query()->create([
        'refund_number' => 'RF-'.Str::upper(Str::random(12)),
        'order_id' => $context['order']->id,
        'user_id' => $context['customer']->id,
        'status' => 'requested',
        'requested_amount' => $context['order']->total_amount,
        'reason_type' => 'product_damaged',
        'reason' => 'Sản phẩm bị hư hỏng',
        'evidence_paths' => $paths,
    ]);
}

function refundEvidenceJpeg(string $name = 'proof.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==', true),
    )->mimeType('image/jpeg');
}

function refundEvidenceMp4(string $name = 'proof.mp4'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free",
    )->mimeType('video/mp4');
}

test('new evidence uses MIME classification, private refund keys, and generic resources do not leak keys', function (): void {
    Storage::fake('local');
    $context = createRefundEvidenceContext();

    $response = $this->actingAs($context['customer'])->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        [
            'reason_type' => 'product_damaged',
            // Deliberately misleading filename: server MIME must win.
            'evidence' => [refundEvidenceJpeg('looks-like-video.mp4')],
        ],
        ['Accept' => 'application/json'],
    )->assertCreated()
        ->assertJsonPath('data.evidence_count', 1)
        ->assertJsonPath('data.has_evidence', true);

    $refund = Refund::query()->sole();
    $key = $refund->evidence_paths[0];

    expect($key)->toMatch("~^private/refunds/{$refund->id}/evidence/images/[0-9a-f-]{36}\\.jpg$~")
        ->and($response->json('data'))->not->toHaveKeys(['evidence_paths', 'evidence_urls']);
    Storage::disk('local')->assertExists($key);
});

test('new MP4 evidence is stored under the private video prefix without altering the upload', function (): void {
    Storage::fake('local');
    $context = createRefundEvidenceContext();
    $original = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free";

    $this->actingAs($context['customer'])->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        ['reason_type' => 'product_damaged', 'evidence' => [refundEvidenceMp4()]],
        ['Accept' => 'application/json'],
    )->assertCreated();

    $refund = Refund::query()->sole();
    $key = $refund->evidence_paths[0];

    expect($key)->toMatch("~^private/refunds/{$refund->id}/evidence/videos/[0-9a-f-]{36}\\.mp4$~")
        ->and(Storage::disk('local')->get($key))->toBe($original);
});

test('customer evidence endpoint is owner scoped and proxies private local files without exposing a raw key', function (): void {
    Storage::fake('local');
    $context = createRefundEvidenceContext();
    $refund = createRefundEvidenceRefund($context, []);
    $key = "private/refunds/{$refund->id}/evidence/images/".Str::uuid().'.jpg';
    Storage::disk('local')->put($key, 'private-image');
    $refund->update(['evidence_paths' => [
        $key,
        'https://attacker.example/not-evidence.jpg',
        'refund-evidence/../secret.jpg',
    ]]);

    $response = $this->actingAs($context['customer'])
        ->getJson("/api/v1/customer/refunds/{$refund->id}/evidence")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertJsonCount(1, 'data.evidence')
        ->assertJsonPath('data.evidence.0.type', 'image')
        ->assertJsonPath('data.evidence.0.expires_in', null)
        ->assertJsonPath('data.evidence.0.delivery', 'authenticated_proxy')
        ->assertJsonMissingPath('data.evidence.0.key');

    $url = $response->json('data.evidence.0.url');
    expect($url)->toContain("/api/v1/customer/refunds/{$refund->id}/evidence/0")
        ->and($response->json('data.evidence.0.expires_at'))->toBeNull();
    $this->get($url)
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertStreamedContent('private-image');

    $this->actingAs($context['other_customer'])
        ->getJson("/api/v1/customer/refunds/{$refund->id}/evidence")
        ->assertNotFound();

    auth()->forgetGuards();
    $this->getJson("/api/v1/customer/refunds/{$refund->id}/evidence")->assertUnauthorized();
});

test('admin evidence endpoint enforces branch scope and staff role boundaries', function (): void {
    Storage::fake('local');
    $context = createRefundEvidenceContext();
    $refund = createRefundEvidenceRefund($context, []);
    $key = "private/refunds/{$refund->id}/evidence/videos/".Str::uuid().'.mp4';
    Storage::disk('local')->put($key, 'video');
    $refund->update(['evidence_paths' => [$key]]);

    $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $ownManager = User::factory()->create(['role' => UserRole::BranchManager, 'branch_id' => $context['branch']->id]);
    $otherManager = User::factory()->create(['role' => UserRole::BranchManager, 'branch_id' => $context['other_branch']->id]);

    $this->actingAs($superAdmin)->getJson("/api/v1/admin/refunds/{$refund->id}/evidence")
        ->assertOk()->assertJsonPath('data.evidence.0.type', 'video');
    $detail = $this->getJson("/api/v1/admin/refunds/{$refund->id}")
        ->assertOk()
        ->assertJsonPath('data.evidence_count', 1)
        ->assertJsonPath('data.has_evidence', true);
    expect($detail->json('data'))->not->toHaveKeys(['evidence_paths', 'evidence_urls']);
    $this->actingAs($ownManager)->getJson("/api/v1/admin/refunds/{$refund->id}/evidence")->assertOk();
    $this->actingAs($otherManager)->getJson("/api/v1/admin/refunds/{$refund->id}/evidence")->assertNotFound();

    foreach ([UserRole::Technician, UserRole::Cashier] as $role) {
        $staff = User::factory()->create(['role' => $role, 'branch_id' => $context['branch']->id]);
        $this->actingAs($staff)->getJson("/api/v1/admin/refunds/{$refund->id}/evidence")->assertForbidden();
    }
});

test('legacy evidence remains readable only through an authenticated authorized proxy', function (): void {
    Storage::fake('public');
    $context = createRefundEvidenceContext();
    Storage::disk('public')->put('refund-evidence/original.jpg', 'legacy-image');
    $refund = createRefundEvidenceRefund($context, ['refund-evidence/original.jpg']);

    $response = $this->actingAs($context['customer'])
        ->getJson("/api/v1/customer/refunds/{$refund->id}/evidence")
        ->assertOk()
        ->assertJsonPath('data.evidence.0.delivery', 'authenticated_proxy')
        ->assertJsonPath('data.evidence.0.expires_at', null);

    $url = $response->json('data.evidence.0.url');
    expect($url)->toContain("/api/v1/customer/refunds/{$refund->id}/evidence/0")
        ->not->toContain('/storage/refund-evidence/');

    $this->get($url)
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertStreamedContent('legacy-image');

    $this->actingAs($context['other_customer'])->get($url)->assertNotFound();
});

test('a failure on upload N rolls back the refund and cleans every attempted private object', function (): void {
    $context = createRefundEvidenceContext();
    $storage = new class(2) implements PrivateFileServiceContract
    {
        /** @var array<int, string> */
        public array $attempted = [];

        /** @var array<int, string> */
        public array $deleted = [];

        public function __construct(private readonly int $failOn) {}

        public function put(string $key, mixed $contents, ?string $mimeType = null, ?string $originalName = null, array $options = []): MediaObject
        {
            $this->attempted[] = $key;

            if (count($this->attempted) === $this->failOn) {
                throw new RuntimeException('simulated provider failure');
            }

            return new MediaObject(
                key: $key,
                visibility: MediaVisibility::Private,
                mimeType: $mimeType,
                extension: pathinfo($key, PATHINFO_EXTENSION),
                bytes: null,
                originalName: $originalName,
            );
        }

        public function delete(string $key): bool
        {
            $this->deleted[] = $key;

            return true;
        }

        public function exists(string $key): bool
        {
            return false;
        }

        public function response(string $key): ?StreamedResponse
        {
            return null;
        }
    };
    $this->app->instance(PrivateFileServiceContract::class, $storage);

    $this->actingAs($context['customer'])->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        [
            'reason_type' => 'product_damaged',
            'evidence' => [refundEvidenceJpeg('one.jpg'), refundEvidenceJpeg('two.jpg')],
        ],
        ['Accept' => 'application/json'],
    )->assertInternalServerError();

    expect(Refund::query()->count())->toBe(0)
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($storage->attempted)->toHaveCount(2)
        ->and($storage->deleted)->toEqual($storage->attempted);
});

test('a transaction failure after uploads cleans objects and rolls back database state', function (): void {
    $context = createRefundEvidenceContext();
    $storage = new class implements PrivateFileServiceContract
    {
        /** @var array<int, string> */
        public array $stored = [];

        /** @var array<int, string> */
        public array $deleted = [];

        public function put(string $key, mixed $contents, ?string $mimeType = null, ?string $originalName = null, array $options = []): MediaObject
        {
            $this->stored[] = $key;

            return new MediaObject(
                key: $key,
                visibility: MediaVisibility::Private,
                mimeType: $mimeType,
                extension: pathinfo($key, PATHINFO_EXTENSION),
                bytes: null,
                originalName: $originalName,
            );
        }

        public function delete(string $key): bool
        {
            $this->deleted[] = $key;

            return true;
        }

        public function exists(string $key): bool
        {
            return false;
        }

        public function response(string $key): ?StreamedResponse
        {
            return null;
        }
    };
    $this->app->instance(PrivateFileServiceContract::class, $storage);

    $repository = Mockery::mock(RefundRepository::class, [app(Refund::class)])->makePartial();
    $repository->shouldReceive('updateEvidencePaths')->once()->andThrow(new RuntimeException('simulated transaction failure'));
    $this->app->instance(RefundRepository::class, $repository);

    $this->actingAs($context['customer'])->post(
        "/api/v1/customer/orders/{$context['order']->id}/refund",
        ['reason_type' => 'product_damaged', 'evidence' => [refundEvidenceJpeg()]],
        ['Accept' => 'application/json'],
    )->assertInternalServerError();

    expect(Refund::query()->count())->toBe(0)
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($storage->stored)->toHaveCount(1)
        ->and($storage->deleted)->toEqual($storage->stored);
});
