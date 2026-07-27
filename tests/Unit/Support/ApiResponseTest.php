<?php

namespace Tests\Unit\Support;

use App\Http\Controllers\Api\V1\BaseController;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

test('it creates a successful response with the standard envelope', function (): void {
    $response = ApiResponse::success(
        data: ['id' => 1],
        message: 'Thành công',
        status: 201,
        meta: ['pagination' => ['current_page' => 1]],
    );

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true))->toBe([
            'success' => true,
            'data' => ['id' => 1],
            'message' => 'Thành công',
            'meta' => ['pagination' => ['current_page' => 1]],
        ]);
});

test('it creates an error response with the standard envelope', function (): void {
    $response = ApiResponse::error(
        message: 'Không tìm thấy dữ liệu.',
        status: 404,
    );

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'data' => null,
            'message' => 'Không tìm thấy dữ liệu.',
            'meta' => [],
        ]);
});

test('the base controller resolves resources into the standard envelope', function (): void {
    $controller = new class extends BaseController
    {
        public function makeSuccessResponse(
            Request $request,
            JsonResource $resource,
            string $message,
        ): JsonResponse {
            return $this->successResponse($request, $resource, $message);
        }
    };
    $request = Request::create('/api/v1/products/1');
    $resource = JsonResource::make(['id' => 1, 'name' => 'Mizuki Serum']);

    $response = $controller->makeSuccessResponse($request, $resource, 'Thành công');

    expect($response->getData(true))->toBe([
        'success' => true,
        'data' => ['id' => 1, 'name' => 'Mizuki Serum'],
        'message' => 'Thành công',
        'meta' => [],
    ]);
});
