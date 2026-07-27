<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseController extends Controller
{
    /**
     * Transform an API Resource into the project-wide success envelope.
     *
     * @param array<string, mixed> $meta
     */
    protected function successResponse(
        Request $request,
        JsonResource|null $resource,
        string $message = '',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $resolved = $resource?->resolve($request) ?? [];

        return ApiResponse::success(
            data: $resolved,
            message: $message,
            status: $status,
            meta: $meta,
        );
    }

    /**
     * Transform a paginated API Resource into the project-wide success envelope.
     */
    protected function paginatedResponse(
        Request $request,
        JsonResource $resource,
        LengthAwarePaginator $paginator,
        string $message = '',
        int $status = 200,
    ): JsonResponse {
        return $this->successResponse(
            request: $request,
            resource: $resource,
            message: $message,
            status: $status,
            meta: [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }

    /**
     * Return a project-wide error envelope.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $meta
     */
    protected function errorResponse(
        string $message,
        int $status,
        array|null $data = null,
        array $meta = [],
    ): JsonResponse {
        return ApiResponse::error(
            message: $message,
            status: $status,
            data: $data,
            meta: $meta,
        );
    }

    /**
     * Return a address info
     *
     * @param array<string
     */
    protected function successResponseRaw(
        Request $request,
        mixed $data,
        string $message = '',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        return ApiResponse::success(
            data: $data,
            message: $message,
            status: $status,
            meta: $meta,
        );
    }
}
