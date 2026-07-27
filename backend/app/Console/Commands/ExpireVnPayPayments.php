<?php

namespace App\Console\Commands;

use App\Services\Payment\VnPayExpirationService;
use Illuminate\Console\Command;

class ExpireVnPayPayments extends Command
{
    protected $signature = 'payments:expire-vnpay
                            {--batch=100 : Số payment tối đa xử lý trong một lần chạy}';

    protected $description = 'Hủy đơn hàng có giao dịch VNPay chờ thanh toán đã hết hạn';

    public function handle(VnPayExpirationService $service): int
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

        $this->info("Đã xử lý: {$result['processed']}");
        $this->line("Bỏ qua: {$result['skipped']}");
        $this->line("Thất bại: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
