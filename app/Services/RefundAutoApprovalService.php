<?php

namespace App\Services;

use App\Repositories\RefundRepository;
use Carbon\CarbonInterface;
use Throwable;

class RefundAutoApprovalService extends BaseService
{
    private const AUTO_APPROVAL_NOTE = 'Tự động duyệt do quá hạn phản hồi';

    public function __construct(
        private readonly RefundRepository $refunds,
    ) {
    }

    /**
     * @return array{processed: int, skipped: int, failed: int}
     */
    public function processExpired(int $batchSize = 100): array
    {
        $timeoutHours = max(0, (int) config('refund.auto_approve_hours', 48));
        $cutoff = now()->subHours($timeoutHours);
        $refundIds = $this->refunds->expiredRequestedIds($cutoff, $batchSize);
        $result = ['processed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($refundIds as $refundId) {
            try {
                $processed = $this->refunds->transaction(
                    fn (): bool => $this->processOne((int) $refundId, $cutoff),
                );

                $result[$processed ? 'processed' : 'skipped']++;
            } catch (Throwable) {
                $result['failed']++;
            }
        }

        return $result;
    }

    private function processOne(int $refundId, CarbonInterface $cutoff): bool
    {
        $refund = $this->refunds->lockExpiredRequested($refundId, $cutoff);

        if (
            $refund === null
            || $refund->status !== 'requested'
            || $refund->created_at->greaterThan($cutoff)
        ) {
            return false;
        }

        $this->refunds->autoApprove($refund, self::AUTO_APPROVAL_NOTE);

        return true;
    }
}
