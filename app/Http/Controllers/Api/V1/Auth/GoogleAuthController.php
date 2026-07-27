<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use App\Http\Resources\Auth\OAuthRedirectResource;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleAuthController extends BaseController
{
    public function __construct(
        private readonly GoogleAuthService $auth,
    ) {
    }

    public function redirect(Request $request): JsonResponse
    {
        return $this->successResponse(
            request: $request,
            resource: new OAuthRedirectResource(['redirect_url' => $this->auth->redirectUrl()]),
            message: 'Tạo liên kết đăng nhập Google thành công!',
        );
    }

    public function callback(Request $request): JsonResponse
    {
        $user = $this->auth->handleCallback($request);

        return $this->successResponse(
            request: $request,
            resource: new AuthenticatedUserResource($user),
            message: 'Đăng nhập Google thành công!',
        );
    }
}
