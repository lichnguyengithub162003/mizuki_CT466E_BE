<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\BaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class CustomerAuthService extends BaseService
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array{name: string, email: string, password: string} $data
     */
    public function register(array $data, Request $request): User
    {
        $user = $this->users->createCustomer([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
        ]);

        $this->authenticate($user, $request);

        return $user;
    }

    /**
     * @param array{email: string, password: string} $data
     *
     * @throws AuthenticationException
     */
    public function login(array $data, Request $request): User
    {
        $user = $this->userFromCredentials($data);

        if ($user->role !== UserRole::Customer) {
            throw new AuthenticationException('Tài khoản không có quyền đăng nhập khu vực khách hàng!');
        }

        $this->authenticate($user, $request);

        return $user;
    }

    /**
     * @param array{email: string, password: string} $data
     *
     * @throws AuthenticationException
     */
    public function staffLogin(array $data, Request $request): User
    {
        $user = $this->userFromCredentials($data);

        if ($user->role === UserRole::Customer) {
            throw new AuthenticationException('Vui lòng đăng nhập tại khu vực khách hàng!');
        }

        $this->authenticate($user, $request);

        return $user;
    }

    public function currentUser(User $user): User
    {
        return $user;
    }

    /**
     * @throws AuthorizationException
     */
    public function currentCustomer(User $user): User
    {
        if ($user->role !== UserRole::Customer) {
            throw new AuthorizationException('Tài khoản không có quyền truy cập khu vực khách hàng!');
        }

        return $user;
    }

    /**
     * @throws AuthorizationException
     */
    public function logout(User $user, Request $request): void
    {
        $this->currentCustomer($user);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Auth::forgetGuards();
    }

    private function authenticate(User $user, Request $request): void
    {
        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }

    /**
     * @param array{email: string, password: string} $data
     *
     * @throws AuthenticationException
     */
    private function userFromCredentials(array $data): User
    {
        $user = $this->users->findByEmail(strtolower(trim($data['email'])));

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new AuthenticationException('Thông tin đăng nhập không đúng!');
        }

        return $user;
    }

    public function forgotPassword(string $email): void
{
    Password::sendResetLink(
        ['email' => $email],
        function ($user, $token) use ($email) {
            $resetUrl = config('app.frontend_url', 'http://localhost:5173')
                . '/reset-password?token=' . $token
                . '&email=' . urlencode($email);

            $user->sendPasswordResetNotification($token);
        }
    );
}

    public function resetPassword(array $data): void
{
    $status = Password::reset(
        [
            'email'                 => $data['email'],
            'password'              => $data['password'],
            'password_confirmation' => $data['password'],
            'token'                 => $data['token'],
        ],
        function ($user, $password) {
            $user->forceFill([
                'password'       => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        }
    );

    if ($status !== Password::PASSWORD_RESET) {
        throw new \Exception('Token đặt lại mật khẩu không hợp lệ hoặc đã hết hạn');
    }
}
}
