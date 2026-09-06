<?php

namespace App\Services;

use App\Models\Refund;
use App\Models\User;
use App\Repositories\RefundRepository;
use App\Services\Media\PrivateFileServiceContract;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RefundEvidenceService
{
    public function __construct(
        private readonly RefundRepository $refunds,
        private readonly PrivateFileServiceContract $privateFiles,
    ) {}

    /** @return array<string, mixed>|null */
    public function forCustomer(User $user, int $refundId): ?array
    {
        $refund = $this->refunds->findForCustomer($refundId, $user->id);

        return $refund === null ? null : $this->describe($refund, 'api.v1.customer.refunds.evidence.download');
    }

    /** @return array<string, mixed>|null */
    public function forAdmin(User $user, int $refundId): ?array
    {
        $refund = $this->refunds->findForAdmin($refundId, $user->role, $user->branch_id);

        return $refund === null ? null : $this->describe($refund, 'api.v1.admin.refunds.evidence.download');
    }

    public function downloadForCustomer(User $user, int $refundId, int $index): ?StreamedResponse
    {
        return $this->response($this->refunds->findForCustomer($refundId, $user->id), $index);
    }

    public function downloadForAdmin(User $user, int $refundId, int $index): ?StreamedResponse
    {
        $refund = $this->refunds->findForAdmin($refundId, $user->role, $user->branch_id);

        return $this->response($refund, $index);
    }

    /** @return array<string, mixed> */
    private function describe(Refund $refund, string $legacyRoute): array
    {
        $items = [];

        foreach ($refund->evidence_paths ?? [] as $index => $path) {
            if (! is_string($path)) {
                continue;
            }

            $privateType = $this->privateType($refund, $path);

            $legacyType = $this->legacyType($path);

            if ($privateType !== null || $legacyType !== null) {
                $items[] = [
                    'type' => $privateType ?? $legacyType,
                    'url' => route($legacyRoute, ['refund' => $refund->id, 'evidence' => $index]),
                    'expires_at' => null,
                    'expires_in' => null,
                    'delivery' => 'authenticated_proxy',
                ];
            }
        }

        return [
            'evidence' => $items,
            'expires_at' => null,
            'expires_in' => null,
        ];
    }

    private function response(?Refund $refund, int $index): ?StreamedResponse
    {
        if ($refund === null || $index < 0) {
            return null;
        }

        $paths = $refund->evidence_paths ?? [];
        $path = $paths[$index] ?? null;

        if (! is_string($path)) {
            return null;
        }

        if ($this->privateType($refund, $path) !== null) {
            return $this->privateFiles->response($path);
        }

        if ($this->legacyType($path) === null) {
            return null;
        }

        $disk = $this->legacyDisk();

        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function privateType(Refund $refund, string $path): ?string
    {
        $prefix = 'private/refunds/'.$refund->id.'/evidence/';

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        if (preg_match('~^'.preg_quote($prefix, '~').'images/[0-9a-f-]{36}\.(?:jpg|png)$~i', $path) === 1) {
            return 'image';
        }

        return preg_match('~^'.preg_quote($prefix, '~').'videos/[0-9a-f-]{36}\.mp4$~i', $path) === 1
            ? 'video'
            : null;
    }

    private function legacyType(string $path): ?string
    {
        if (rawurldecode($path) !== $path
            || preg_match('~^refund-evidence/[A-Za-z0-9][A-Za-z0-9/_\-.]*\.(jpg|jpeg|png|mp4)$~i', $path, $matches) !== 1
            || str_contains($path, '//')
            || preg_match('~(?:^|/)\.\.?(/|$)~', $path) === 1) {
            return null;
        }

        return strtolower($matches[1]) === 'mp4' ? 'video' : 'image';
    }

    private function legacyDisk(): FilesystemAdapter
    {
        return Storage::disk((string) config('filesystems.refund_evidence_legacy_disk', 'public'));
    }
}
