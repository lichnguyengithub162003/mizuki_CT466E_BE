<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Admin\UploadImageRequest;
use App\Services\Admin\AdminMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends BaseController
{
    public function __construct(private readonly AdminMediaService $media) {}

    public function storeImage(UploadImageRequest $request): JsonResponse
    {
        return $this->successResponseRaw(
            request: $request,
            data: $this->media->stageImage($request->user(), $request->file('image')),
            message: 'Tải ảnh lên thành công!',
            status: 201,
        )->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function destroy(Request $request, string $uploadToken): JsonResponse
    {
        $upload = $this->media->deleteStaging($request->user(), $uploadToken);

        return $upload === null
            ? $this->errorResponse('Không tìm thấy upload staging', 404)
            : $this->successResponseRaw($request, ['status' => $upload->status->value], 'Đã xóa upload staging!');
    }

    public function preview(Request $request, string $uploadToken): StreamedResponse|JsonResponse
    {
        return $this->media->previewStaging($request->user(), $uploadToken)
            ?? $this->errorResponse('Không tìm thấy upload staging', 404);
    }
}
