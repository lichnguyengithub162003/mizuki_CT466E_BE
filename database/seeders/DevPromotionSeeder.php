<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Seeder;

class DevPromotionSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->firstOrFail();
        $testUser = User::query()->where('email', 'test@mizuki.com')->firstOrFail();

        $publicPromotions = [
            [
                'code' => 'MIZUKI10',
                'name' => 'Giảm 10% đơn hàng',
                'description' => 'Giảm 10%, tối đa 100.000 đ cho đơn từ 200.000 đ.',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'max_discount_amount' => 100_000,
                'minimum_order_amount' => 200_000,
                'usage_limit' => 500,
                'usage_count' => 0,
                'per_user_limit' => 2,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(2),
                'is_active' => true,
            ],
            [
                'code' => 'EXPIRED50',
                'name' => 'Voucher mẫu đã hết hạn',
                'description' => 'Voucher dùng để kiểm thử điều kiện hết hạn.',
                'discount_type' => 'fixed_amount',
                'discount_value' => 50_000,
                'max_discount_amount' => null,
                'minimum_order_amount' => 100_000,
                'usage_limit' => 100,
                'usage_count' => 0,
                'per_user_limit' => 1,
                'starts_at' => now()->subMonths(2),
                'ends_at' => now()->subDay(),
                'is_active' => true,
            ],
            [
                'code' => 'SOLDOUT20',
                'name' => 'Voucher mẫu đã hết lượt',
                'description' => 'Voucher dùng để kiểm thử giới hạn tổng lượt sử dụng.',
                'discount_type' => 'fixed_amount',
                'discount_value' => 20_000,
                'max_discount_amount' => null,
                'minimum_order_amount' => 100_000,
                'usage_limit' => 10,
                'usage_count' => 10,
                'per_user_limit' => 1,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
                'is_active' => true,
            ],
        ];

        foreach ($publicPromotions as $attributes) {
            $promotion = $this->upsertPromotion($attributes + ['scope' => null]);
            $promotion->branches()->sync([$branch->id]);
        }

        $personalPromotion = $this->upsertPromotion([
            'code' => 'MYMIZUKI50',
            'name' => 'Voucher cá nhân 50.000 đ',
            'description' => 'Voucher dành riêng cho tài khoản khách hàng thử nghiệm.',
            'discount_type' => 'fixed_amount',
            'discount_value' => 50_000,
            'max_discount_amount' => null,
            'minimum_order_amount' => 150_000,
            'usage_limit' => 1,
            'usage_count' => 0,
            'per_user_limit' => 1,
            'scope' => ['user_ids' => [$testUser->id]],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
        ]);
        $personalPromotion->branches()->sync([$branch->id]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function upsertPromotion(array $attributes): Promotion
    {
        return Promotion::query()->updateOrCreate(
            ['code' => $attributes['code']],
            $attributes + [
                'applies_to' => 'order',
                'rules' => null,
            ],
        );
    }
}
