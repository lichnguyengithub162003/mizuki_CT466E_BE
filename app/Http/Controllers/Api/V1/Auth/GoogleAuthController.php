<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\Auth\GoogleOAuthException;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\Auth\OAuthRedirectResource;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class GoogleAuthController extends BaseController
{
    public function __construct(
        private readonly GoogleAuthService $auth,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/auth/google/redirect",
     *     operationId="createGoogleOAuthRedirect",
     *     tags={"Authentication"},
     *     summary="Tạo URL đăng nhập Google",
     *
     *     @OA\Parameter(
     *         name="redirect",
     *         in="query",
     *         required=false,
     *         description="Đường dẫn frontend tương đối sau đăng nhập; phải bắt đầu bằng một dấu /",
     *
     *         @OA\Schema(type="string", example="/account")
     *     ),
     *
     *     @OA\Response(response=200, description="Tạo URL Google thành công"),
     *     @OA\Response(response=429, description="Vượt giới hạn đăng nhập")
     * )
     */
    public function redirect(Request $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: new OAuthRedirectResource(['redirect_url' => $this->auth->redirectUrl($request)]),
            message: 'Tạo liên kết đăng nhập Google thành công!',
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/auth/google/callback",
     *     operationId="completeGoogleOAuthCallback",
     *     tags={"Authentication"},
     *     summary="Hoàn tất đăng nhập Google và chuyển trình duyệt về frontend",
     *
     *     @OA\Parameter(name="code", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="state", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="error", in="query", required=false, @OA\Schema(type="string")),
     *
     *     @OA\Response(
     *         response=302,
     *         description="Thành công: status=success. Thất bại: oauth_error là google_cancelled, google_invalid_callback, google_unverified_email, google_staff_account hoặc google_auth_failed",
     *
     *         @OA\Header(header="Location", @OA\Schema(type="string", format="uri"))
     *     )
     * )
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $this->auth->handleCallback($request);

            return redirect()->away($this->auth->successRedirectUrl($request));
        } catch (GoogleOAuthException $exception) {
            return redirect()->away(
                $this->auth->failureRedirectUrl($request, $exception->safeCode),
            );
        }
    }
}
