<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Support\ApiResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Http\Exceptions\PostTooLargeException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
        $middleware->redirectGuestsTo(fn() => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() === 'Unauthenticated.'
                    ? 'Bạn cần đăng nhập để tiếp tục'
                    : ($exception->getMessage() ?: 'Bạn cần đăng nhập để tiếp tục'),
                status: 401,
            );
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: 'Bạn không có quyền thực hiện thao tác này',
                status: 403,
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'Dữ liệu không hợp lệ',
                status: 422,
                data: ['errors' => $exception->errors()],
            );
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'Quá nhiều yêu cầu. Vui lòng thử lại sau!',
                status: 429,
                meta: [
                    'retry_after' => (int) ($exception->getHeaders()['Retry-After'] ?? 0),
                ],
            )->withHeaders($exception->getHeaders());
        });

        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'File tải lên quá lớn, vui lòng chọn ảnh nhỏ hơn',
                status: 413,
            );
        });

        $exceptions->render(function (InvalidArgumentException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage(),
                status: 400,
            );
        });
    })->create();
