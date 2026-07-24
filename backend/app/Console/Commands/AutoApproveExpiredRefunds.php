<?php

namespace App\Console\Commands;

use App\Services\RefundAutoApprovalService;
use Illuminate\Console\Command;

class AutoApproveExpiredRefunds extends Command
{
    protected $signature = 'refunds:auto-approve
                            {--batch=100 : Số refund tối đa xử lý trong một lần chạy}';

    protected $description = 'Tự động duyệt các yêu cầu hoàn tiền quá hạn phản hồi';

    public function handle(RefundAutoApprovalService $service): int
    {
        $batchSize = filter_var(
            $this->option('batch'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]],
        );

        if ($batchSize === false) {
            $this->error('Batch phải là số nguyên từ 1 đến 1000.');

            return self::INVALID;
        }

        $result = $service->processExpired($batchSize);

        $this->info("Đã tự động duyệt: {$result['processed']}");
        $this->line("Bỏ qua: {$result['skipped']}");
        $this->line("Thất bại: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
