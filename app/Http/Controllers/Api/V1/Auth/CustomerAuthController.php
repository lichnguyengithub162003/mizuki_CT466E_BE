<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordRecoveryOtpRequest;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Http\Resources\Auth\PasswordRecoveryRequestResource;
use App\Http\Resources\Auth\PasswordRecoveryVerificationResource;
use App\Models\User;
use App\Services\Auth\CustomerAuthService;
use App\Services\Auth\PasswordRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class CustomerAuthController extends BaseController
{
    public function __construct(
        private readonly CustomerAuthService $auth,
        private readonly PasswordRecoveryService $passwordRecovery,
    ) {}

    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated(), $request);

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($user),
            message: 'Đăng ký tài khoản thành công!',
            status: 201,
        );
    }

    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $user = $this->auth->login($request->validated(), $request);

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($user),
            message: 'Đăng nhập thành công!',
        );
    }

    public function staffLogin(CustomerLoginRequest $request): JsonResponse
    {
        $user = $this->auth->staffLogin($request->validated(), $request);

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($user),
            message: 'Đăng nhập thành công!',
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $user = $this->auth->currentUser($authenticatedUser);

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($user),
            message: 'Lấy thông tin tài khoản thành công!',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $this->auth->logout($authenticatedUser, $request);

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($authenticatedUser),
            message: 'Đăng xuất thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/forgot-password",
     *     operationId="requestPasswordRecoveryOtp",
     *     tags={"Authentication"},
     *     summary="Gửi mã OTP khôi phục mật khẩu qua email",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"email"},
     *
     *         @OA\Property(property="email", type="string", format="email", example="customer@example.com")
     *     )),
     *
     *     @OA\Response(response=200, description="Đã xử lý yêu cầu gửi OTP", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object",
     *             @OA\Property(property="resend_after", type="integer", example=60),
     *             @OA\Property(property="expires_in", type="integer", example=300)
     *         ),
     *         @OA\Property(property="message", type="string", example="Mã xác thực đã được gửi đến email của bạn!"),
     *         @OA\Property(property="meta", type="object")
     *     )),
     *
     *     @OA\Response(response=422, description="Email không hợp lệ, đang trong thời gian chờ gửi lại hoặc gửi mail thất bại"),
     *     @OA\Response(response=429, description="Vượt giới hạn yêu cầu theo email hoặc IP")
     * )
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $result = $this->passwordRecovery->requestOtp($request->validated('email'));

        return $this->successResponse(
            request: $request,
            resource: new PasswordRecoveryRequestResource($result),
            message: 'Mã xác thực đã được gửi đến email của bạn!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/forgot-password/verify",
     *     operationId="verifyPasswordRecoveryOtp",
     *     tags={"Authentication"},
     *     summary="Xác thực mã OTP khôi phục mật khẩu",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"email", "code"},
     *
     *         @OA\Property(property="email", type="string", format="email", example="customer@example.com"),
     *         @OA\Property(property="code", type="string", minLength=6, maxLength=6, pattern="^[0-9]{6}$", example="123456")
     *     )),
     *
     *     @OA\Response(response=200, description="OTP hợp lệ", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean", example=true),
     *         @OA\Property(property="data", type="object",
     *             @OA\Property(property="verification_token", type="string", example="opaque-64-character-token"),
     *             @OA\Property(property="expires_in", type="integer", example=600)
     *         ),
     *         @OA\Property(property="message", type="string", example="Xác thực mã thành công!"),
     *         @OA\Property(property="meta", type="object")
     *     )),
     *
     *     @OA\Response(response=422, description="Mã sai, đã hết hạn, đã dùng hoặc đã vượt số lần thử"),
     *     @OA\Response(response=429, description="Vượt giới hạn xác thực theo email hoặc IP")
     * )
     */
    public function verifyPasswordRecoveryOtp(VerifyPasswordRecoveryOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->passwordRecovery->verifyOtp($validated['email'], $validated['code']);

        return $this->successResponse(
            request: $request,
            resource: new PasswordRecoveryVerificationResource($result),
            message: 'Xác thực mã thành công!',
        );
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/reset-password",
     *     operationId="resetPasswordWithVerificationToken",
     *     tags={"Authentication"},
     *     summary="Đặt lại mật khẩu bằng verification token",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"email", "verification_token", "password", "password_confirmation"},
     *
     *         @OA\Property(property="email", type="string", format="email", example="customer@example.com"),
     *         @OA\Property(property="verification_token", type="string", minLength=64, maxLength=64),
     *         @OA\Property(property="password", type="string", minLength=8, format="password"),
     *         @OA\Property(property="password_confirmation", type="string", minLength=8, format="password")
     *     )),
     *
     *     @OA\Response(response=200, description="Đặt lại mật khẩu thành công"),
     *     @OA\Response(response=422, description="Validation lỗi hoặc verification token sai, hết hạn, đã dùng, không đúng email"),
     *     @OA\Response(response=429, description="Vượt giới hạn reset theo email hoặc IP")
     * )
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordRecovery->resetPassword($request->validated());

        return $this->successResponse(
            request: $request,
            resource: null,
            message: 'Đặt lại mật khẩu thành công!',
        );
    }
}
