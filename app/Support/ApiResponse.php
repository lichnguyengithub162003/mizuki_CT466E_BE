<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /**
     * Create a successful API response with the project-wide envelope.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $meta
     */
    public static function success(
        array|null $data = null,
        string $message = '',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => $meta,
        ], $status);
    }

    /**
     * Create a failed API response with the project-wide envelope.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $meta
     */
    public static function error(
        string $message,
        int $status,
        array|null $data = null,
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'data' => $data,
            'message' => $message,
            'meta' => $meta,
        ], $status);
    }
}
