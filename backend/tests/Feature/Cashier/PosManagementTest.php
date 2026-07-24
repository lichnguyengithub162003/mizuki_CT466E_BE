<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Category;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{branch: Branch, cashier: User, product: Product, variant: ProductVariant, inventory: BranchInventory} */
function createPosContext(array $variantOverrides = []): array
{
    $token = Str::upper(Str::random(8));
    $branch = Branch::query()->create([
        'code' => 'POS'.$token,
        'name' => 'Mizuki POS '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $cashier = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $branch->id,
    ]);
    $category = Category::query()->create([
        'name' => 'POS '.$token,
        'slug' => 'pos-category-'.strtolower($token),
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'POS Brand '.$token,
        'slug' => 'pos-brand-'.strtolower($token),
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Sữa rửa mặt Mizuki '.$token,
        'slug' => 'pos-product-'.strtolower($token),
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create(array_merge([
        'product_id' => $product->id,
        'name' => '100 ml',
        'sku' => 'POS-SKU-'.$token,
        'barcode' => '893'.random_int(1000000000, 9999999999),
        'attributes' => ['capacity' => '100 ml'],
        'price' => 200_000,
        'sale_price' => 175_000,
        'weight' => 100,
        'sort_order' => 0,
        'is_active' => true,
    ], $variantOverrides));
    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 10,
        'reserved_quantity' => 2,
        'reorder_level' => 2,
    ]);

    return compact('branch', 'cashier', 'product', 'variant', 'inventory');
}

function createOpenPosSession(User $cashier, Branch $branch): PosSession
{
    return PosSession::query()->create([
        'code' => Str::random(48),
        'cashier_id' => $cashier->id,
        'branch_id' => $branch->id,
        'payment_method' => PaymentMethod::Cash,
        'status' => 'open',
        'expires_at' => now()->addMinutes(30),
    ]);
}

function addPosSessionItem(PosSession $session, ProductVariant $variant, int $quantity = 1): void
{
    $session->items()->create([
        'product_variant_id' => $variant->id,
        'product_name' => $variant->product->name,
        'variant_name' => $variant->name,
        'sku' => $variant->sku,
        'variant_attributes' => $variant->attributes,
        'unit_price' => $variant->sale_price ?? $variant->price,
        'quantity' => $quantity,
    ]);
}

test('guest and customer cannot access cashier POS while cashier without branch is forbidden', function (): void {
    $paths = [
        ['GET', '/api/v1/cashier/pos/products?keyword=test'],
        ['POST', '/api/v1/cashier/pos/sessions'],
    ];

    foreach ($paths as [$method, $path]) {
        $this->json($method, $path)->assertUnauthorized();
    }

    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]));

    foreach ($paths as [$method, $path]) {
        $this->json($method, $path)->assertForbidden();
    }

    $this->actingAs(User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => null,
    ]));
    $this->postJson('/api/v1/cashier/pos/sessions')->assertForbidden();
});

test('cashier searches active products by name and barcode with branch availability', function (): void {
    $context = createPosContext();
    $this->actingAs($context['cashier']);

    $this->getJson('/api/v1/cashier/pos/products?keyword='.urlencode('Sữa rửa mặt'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.variant_id', $context['variant']->id)
        ->assertJsonPath('data.0.effective_price', 175_000)
        ->assertJsonPath('data.0.available_quantity', 8)
        ->assertJsonPath('data.0.available', true);

    $this->getJson('/api/v1/cashier/pos/products?keyword='.$context['variant']->barcode)
        ->assertOk()
        ->assertJsonPath('data.0.barcode', $context['variant']->barcode);
});

test('exact barcode lookup returns the variant and stock for cashier branch', function (): void {
    $context = createPosContext();
    $otherBranch = createPosContext()['branch'];
    BranchInventory::query()->create([
        'branch_id' => $otherBranch->id,
        'product_variant_id' => $context['variant']->id,
        'quantity' => 99,
        'reserved_quantity' => 0,
        'reorder_level' => 0,
    ]);
    $this->actingAs($context['cashier']);

    $this->getJson("/api/v1/cashier/pos/products/barcode/{$context['variant']->barcode}")
        ->assertOk()
        ->assertJsonPath('data.variant_id', $context['variant']->id)
        ->assertJsonPath('data.available_quantity', 8);

    $this->getJson('/api/v1/cashier/pos/products/barcode/NOT-FOUND')
        ->assertNotFound();
});

test('cashier creates a session and can add update and remove items', function (): void {
    $context = createPosContext();
    $this->actingAs($context['cashier']);

    $response = $this->postJson('/api/v1/cashier/pos/sessions')
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonCount(0, 'data.items');
    $code = $response->json('data.code');

    expect(strlen($code))->toBe(48);

    $this->postJson("/api/v1/cashier/pos/sessions/{$code}/items", [
        'variant_id' => $context['variant']->id,
        'quantity' => 2,
    ])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.total_amount', 350_000);

    $this->patchJson("/api/v1/cashier/pos/sessions/{$code}/items/{$context['variant']->id}", [
        'quantity' => 3,
    ])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 3)
        ->assertJsonPath('data.total_amount', 525_000);

    $this->deleteJson("/api/v1/cashier/pos/sessions/{$code}/items/{$context['variant']->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data.items');
});

test('customer phone matching attaches an account and unknown phone stores snapshot only', function (): void {
    $context = createPosContext();
    $registered = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Khách Mizuki',
        'phone' => '0901234567',
    ]);
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    $this->actingAs($context['cashier']);

    $this->patchJson("/api/v1/cashier/pos/sessions/{$session->code}/customer", [
        'customer_phone' => '0901234567',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.user_id', $registered->id)
        ->assertJsonPath('data.customer.name', 'Khách Mizuki')
        ->assertJsonPath('data.customer.registered', true);

    $userCount = User::query()->count();
    $this->patchJson("/api/v1/cashier/pos/sessions/{$session->code}/customer", [
        'customer_phone' => '0912345678',
        'customer_name' => 'Khách chưa đăng ký',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.user_id', null)
        ->assertJsonPath('data.customer.name', 'Khách chưa đăng ký')
        ->assertJsonPath('data.customer.registered', false);

    expect(User::query()->count())->toBe($userCount);
    $this->assertDatabaseHas('pos_sessions', [
        'id' => $session->id,
        'customer_user_id' => null,
        'customer_name' => 'Khách chưa đăng ký',
        'customer_phone' => '0912345678',
    ]);
});

test('walk in customer can remain anonymous without creating an account', function (): void {
    $context = createPosContext();
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    $userCount = User::query()->count();
    $this->actingAs($context['cashier']);

    $this->patchJson("/api/v1/cashier/pos/sessions/{$session->code}/customer", [
        'customer_phone' => null,
        'customer_name' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.customer', null);

    expect(User::query()->count())->toBe($userCount);
});

test('public display requires a valid code and returns configured bank transfer QR data', function (): void {
    config()->set([
        'pos.bank.bin' => '970422',
        'pos.bank.account_number' => '123456789',
        'pos.bank.account_name' => 'MIZUKI BEAUTY',
        'pos.bank.name' => 'MB Bank',
        'pos.bank.transfer_prefix' => 'MIZUKI',
    ]);
    $context = createPosContext();
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    addPosSessionItem($session, $context['variant'], 2);
    $session->update(['payment_method' => PaymentMethod::BankTransfer]);
    $shortCode = strtoupper(substr($session->code, 0, 10));

    $this->getJson("/api/v1/pos/display/{$session->code}")
        ->assertOk()
        ->assertJsonPath('data.total_amount', 350_000)
        ->assertJsonPath('data.bank_transfer.bank_name', 'MB Bank')
        ->assertJsonPath('data.bank_transfer.account_number', '123456789')
        ->assertJsonPath('data.bank_transfer.amount', 350_000)
        ->assertJsonPath('data.bank_transfer.transfer_content', "MIZUKI {$shortCode}")
        ->assertJsonPath('data.cashier_id', null)
        ->assertJsonPath('data.bank_transfer.qr_url', fn ($url): bool => str_contains($url, 'img.vietqr.io'));

    $this->getJson('/api/v1/pos/display/invalid-code')->assertNotFound();
});

test('POS transfer content is normalized and limited without cutting its identifier', function (
    string $prefix,
    string $expectedPrefix,
): void {
    config()->set([
        'pos.bank.bin' => '970422',
        'pos.bank.account_number' => '123456789',
        'pos.bank.account_name' => 'MIZUKI BEAUTY',
        'pos.bank.transfer_prefix' => $prefix,
    ]);
    $context = createPosContext();
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    addPosSessionItem($session, $context['variant']);
    $session->update(['payment_method' => PaymentMethod::BankTransfer]);
    $identifier = strtoupper(substr($session->code, 0, 10));

    $content = $this->getJson("/api/v1/pos/display/{$session->code}")
        ->assertOk()
        ->json('data.bank_transfer.transfer_content');

    expect($content)
        ->toBe("{$expectedPrefix} {$identifier}")
        ->toMatch('/^[A-Z0-9 -]+$/')
        ->toEndWith($identifier)
        ->and(strlen($content))->toBeLessThanOrEqual(25);
})->with([
    'lowercase becomes uppercase' => ['mizuki pos', 'MIZUKI POS'],
    'Vietnamese accents are removed' => ['Mỹ phẩm', 'MY PHAM'],
    'spaces are preserved and normalized' => ['  MZ   POS  ', 'MZ POS'],
    'hyphen is preserved' => ['MZ-POS', 'MZ-POS'],
    'invalid characters are removed' => ['MZ@#$ POS!', 'MZ POS'],
    'long prefix uses complete short words' => ['THUONG HIEU MIZUKI BEAUTY', 'THUONG HIEU'],
]);

test('confirm creates a counter order reserves stock and cannot run twice', function (): void {
    Event::fake([OrderPlaced::class]);
    $context = createPosContext();
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    addPosSessionItem($session, $context['variant'], 2);
    $session->update([
        'payment_method' => PaymentMethod::BankTransfer,
        'customer_name' => 'Khách tại quầy',
        'customer_phone' => '0987654321',
    ]);
    $this->actingAs($context['cashier']);

    $response = $this->postJson("/api/v1/cashier/pos/sessions/{$session->code}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.order.status', 'confirmed');

    $order = Order::query()->findOrFail($response->json('data.order.id'));
    $payment = $order->payment()->firstOrFail();
    expect($order->user_id)->toBeNull()
        ->and($order->customer_name)->toBe('Khách tại quầy')
        ->and($order->customer_phone)->toBe('0987654321')
        ->and($order->created_by_user_id)->toBe($context['cashier']->id)
        ->and($order->branch_id)->toBe($context['branch']->id)
        ->and($order->channel)->toBe('counter')
        ->and($order->fulfillment_method)->toBe('pickup')
        ->and($order->payment_method)->toBe(PaymentMethod::BankTransfer)
        ->and($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->total_amount)->toBe(350_000)
        ->and($payment->method)->toBe(PaymentMethod::BankTransfer)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->amount)->toBe(350_000)
        ->and($payment->user_id)->toBeNull()
        ->and($payment->processed_by_user_id)->toBe($context['cashier']->id)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($context['inventory']->refresh()->quantity)->toBe(10)
        ->and($context['inventory']->reserved_quantity)->toBe(4);
    $this->assertDatabaseCount('order_items', 1);
    Event::assertDispatched(OrderPlaced::class);

    $this->postJson("/api/v1/cashier/pos/sessions/{$session->code}/confirm")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.status.0', 'Phiên POS đã được xử lý');
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('payments', 1);
});

test('POS cash confirmation creates a paid payment', function (): void {
    $context = createPosContext();
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    addPosSessionItem($session, $context['variant']);
    $this->actingAs($context['cashier']);

    $response = $this->postJson("/api/v1/cashier/pos/sessions/{$session->code}/confirm")
        ->assertOk();

    $order = Order::query()->findOrFail($response->json('data.order.id'));
    $payment = $order->payment()->firstOrFail();

    expect($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->amount)->toBe($order->total_amount)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->processed_by_user_id)->toBe($context['cashier']->id);
});

test('confirm revalidates stock and rolls back without creating an order', function (): void {
    $context = createPosContext();
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    addPosSessionItem($session, $context['variant'], 3);
    $context['inventory']->update(['quantity' => 4, 'reserved_quantity' => 2]);
    $this->actingAs($context['cashier']);

    $this->postJson("/api/v1/cashier/pos/sessions/{$session->code}/confirm")
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.stock.0',
            "Sản phẩm {$context['product']->name} chỉ còn 2 sản phẩm tại chi nhánh",
        );

    $this->assertDatabaseCount('orders', 0);
    expect($context['inventory']->refresh()->reserved_quantity)->toBe(2)
        ->and($session->refresh()->status)->toBe('open');
});

test('a different cashier cannot view modify or confirm another cashiers session', function (): void {
    $context = createPosContext();
    $otherCashier = User::factory()->create([
        'role' => UserRole::Cashier,
        'branch_id' => $context['branch']->id,
    ]);
    $session = createOpenPosSession($context['cashier'], $context['branch']);
    addPosSessionItem($session, $context['variant']);
    $this->actingAs($otherCashier);

    $this->getJson("/api/v1/cashier/pos/sessions/{$session->code}")->assertNotFound();
    $this->postJson("/api/v1/cashier/pos/sessions/{$session->code}/confirm")->assertNotFound();
    $this->patchJson("/api/v1/cashier/pos/sessions/{$session->code}/customer", [
        'customer_phone' => null,
    ])->assertNotFound();

    $this->assertDatabaseCount('orders', 0);
});
