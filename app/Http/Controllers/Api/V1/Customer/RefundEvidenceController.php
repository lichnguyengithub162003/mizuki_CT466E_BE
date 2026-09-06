<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\BaseController;
use App\Services\RefundEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RefundEvidenceController extends BaseController
{
    public function __construct(
        private readonly RefundEvidenceService $evidence,
    ) {}

    public function index(Request $request, int $refund): JsonResponse
    {
        $data = $this->evidence->forCustomer($request->user(), $refund);

        if ($data === null) {
            return $this->notFound();
        }

        return $this->successResponseRaw($request, $data, 'Lấy bằng chứng hoàn tiền thành công!')
            ->withHeaders($this->privateHeaders());
    }

    public function download(Request $request, int $refund, int $evidence): StreamedResponse|JsonResponse
    {
        return $this->evidence->downloadForCustomer($request->user(), $refund, $evidence)
            ?? $this->notFound();
    }

    private function notFound(): JsonResponse
    {
        return $this->errorResponse('Không tìm thấy bằng chứng hoàn tiền', 404);
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache'];
    }
}
