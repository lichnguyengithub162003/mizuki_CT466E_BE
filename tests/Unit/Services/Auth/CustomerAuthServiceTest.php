<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Auth\CustomerAuthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function customerAuthRequest(string $uri = '/api/v1/auth/login', string $method = 'POST'): Request
{
    $request = Request::create($uri, $method);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

test('it registers a customer through the user repository', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));

    $user = $service->register([
        'name' => '  Mizuki Customer  ',
        'email' => '  CUSTOMER@EXAMPLE.COM  ',
        'password' => 'secret-password',
    ], customerAuthRequest('/api/v1/auth/register'));

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Mizuki Customer')
        ->and($user->email)->toBe('customer@example.com')
        ->and($user->role)->toBe(UserRole::Customer)
        ->and($user->branch_id)->toBeNull()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('it logs in a customer with valid credentials', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $authenticatedUser = $service->login([
        'email' => '  CUSTOMER@EXAMPLE.COM  ',
        'password' => 'secret-password',
    ], customerAuthRequest());

    expect($authenticatedUser->is($user))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('it rejects login when the email does not exist', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));

    $service->login([
        'email' => 'missing@example.com',
        'password' => 'secret-password',
    ], customerAuthRequest());
})->throws(AuthenticationException::class, 'Thông tin đăng nhập không đúng');

test('it rejects login when the password is invalid', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $service->login([
        'email' => 'customer@example.com',
        'password' => 'wrong-password',
    ], customerAuthRequest());
})->throws(AuthenticationException::class, 'Thông tin đăng nhập không đúng');

test('it rejects non-customer users from customer login', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    User::factory()->create([
        'email' => 'cashier@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Cashier,
    ]);

    $service->login([
        'email' => 'cashier@example.com',
        'password' => 'secret-password',
    ], customerAuthRequest());
})->throws(AuthenticationException::class, 'Tài khoản không có quyền đăng nhập khu vực khách hàng!');

test('it logs in internal staff through the staff login flow', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    $user = User::factory()->create([
        'email' => 'manager@example.com',
        'password' => 'secret-password',
        'role' => UserRole::BranchManager,
    ]);

    $authenticatedUser = $service->staffLogin([
        'email' => '  MANAGER@EXAMPLE.COM  ',
        'password' => 'secret-password',
    ], customerAuthRequest('/api/v1/auth/staff-login'));

    expect($authenticatedUser->is($user))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('it rejects customers from the staff login flow', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'secret-password',
        'role' => UserRole::Customer,
    ]);

    $service->staffLogin([
        'email' => 'customer@example.com',
        'password' => 'secret-password',
    ], customerAuthRequest('/api/v1/auth/staff-login'));
})->throws(AuthenticationException::class, 'Vui lòng đăng nhập tại khu vực khách hàng!');

test('it returns any current authenticated user for the shared identity endpoint', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    $user = User::factory()->create(['role' => UserRole::Technician]);

    expect($service->currentUser($user)->is($user))->toBeTrue();
});

test('it returns the current authenticated customer', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    $user = User::factory()->create([
        'role' => UserRole::Customer,
    ]);

    expect($service->currentCustomer($user)->is($user))->toBeTrue();
});

test('it rejects non-customer users from the customer account context', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    $user = User::factory()->create([
        'role' => UserRole::Cashier,
    ]);

    $service->currentCustomer($user);
})->throws(AuthorizationException::class, 'Tài khoản không có quyền truy cập khu vực khách hàng!');

test('it logs out an authenticated customer and invalidates the session', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    $user = User::factory()->create([
        'role' => UserRole::Customer,
    ]);
    $request = Request::create('/api/v1/auth/logout', 'POST');
    $request->setLaravelSession($this->app['session.store']);
    $request->session()->put('checkout_step', 'payment');
    $tokenBeforeLogout = $request->session()->token();
    $this->actingAs($user);

    $service->logout($user, $request);

    $this->assertGuest();
    expect($request->session()->has('checkout_step'))->toBeFalse()
        ->and($request->session()->token())->not->toBe($tokenBeforeLogout);
});

test('it rejects non-customer users from logout', function (): void {
    $service = new CustomerAuthService(new UserRepository(new User()));
    $user = User::factory()->create([
        'role' => UserRole::Cashier,
    ]);
    $request = Request::create('/api/v1/auth/logout', 'POST');

    $service->logout($user, $request);
})->throws(AuthorizationException::class, 'Tài khoản không có quyền truy cập khu vực khách hàng!');
