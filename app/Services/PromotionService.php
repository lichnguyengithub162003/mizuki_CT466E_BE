<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Promotion;
use App\Models\User;
use App\Repositories\CartRepository;
use App\Repositories\PromotionRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PromotionService extends BaseService
{
    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly CartRepository $carts,
        private readonly CartService $cartService,
    ) {
    }

    /**
     * @return array{promotions: Collection<int, Promotion>, message: string}
     */
    public function getAvailableForUser(User $user): array
    {
        $cart = $this->cartService->getForUser($user);

        if ($cart->branch_id === null) {
            return [
                'promotions' => collect(),
                'message' => 'Vui lòng chọn chi nhánh để xem voucher khả dụng!',
            ];
        }

        $promotions = $this->promotions
            ->getCandidatesForUser($user->id)
            ->filter(fn (Promotion $promotion): bool => $this->eligibilityError($promotion, $cart, $user) === null)
            ->each(function (Promotion $promotion) use ($cart): void {
                $promotion->setAttribute(
                    'estimated_discount_amount',
                    $this->cartService->calculatePromotionDiscount($promotion, (int) $cart->total_before_discount),
                );
            })
            ->values();

        return [
            'promotions' => $promotions,
            'message' => 'Lấy danh sách voucher khả dụng thành công!',
        ];
    }

    public function applyForUser(User $user, string $code): Cart
    {
        $cart = $this->cartService->getForUser($user);
        $promotion = $this->promotions->findByCodeForUser($code, $user->id);

        if ($promotion === null) {
            $this->throwCodeError('Voucher không tồn tại');
        }

        $error = $this->eligibilityError($promotion, $cart, $user);

        if ($error !== null) {
            $this->throwCodeError($error);
        }

        $this->carts->updatePromotion($cart, $promotion->id);

        return $this->cartService->getForUser($user);
    }

    public function removeForUser(User $user): ?Cart
    {
        $cart = $this->carts->getOrCreateForUser($user->id);

        if ($cart->promotion_id === null) {
            return null;
        }

        $this->carts->updatePromotion($cart, null);

        return $this->cartService->getForUser($user);
    }

    public function validateForCheckout(Promotion $promotion, Cart $cart, User $user): void
    {
        $error = $this->eligibilityError($promotion, $cart, $user);

        if ($error !== null) {
            $this->throwCodeError($error);
        }
    }

    private function eligibilityError(Promotion $promotion, Cart $cart, User $user): ?string
    {
        if (! $promotion->is_active || $promotion->applies_to !== 'order') {
            return 'Voucher đã ngừng hoạt động';
        }

        if ($promotion->starts_at->isFuture()) {
            return 'Voucher chưa đến thời gian áp dụng';
        }

        if ($promotion->ends_at !== null && $promotion->ends_at->isPast()) {
            return 'Voucher đã hết hạn';
        }

        if ($cart->branch_id === null) {
            return 'Vui lòng chọn chi nhánh trước khi áp dụng voucher';
        }

        if ($promotion->branches->isNotEmpty() && ! $promotion->branches->contains('id', $cart->branch_id)) {
            return 'Voucher không áp dụng cho chi nhánh đã chọn';
        }

        if ((int) $cart->total_before_discount < $promotion->minimum_order_amount) {
            return 'Đơn hàng chưa đạt giá trị tối thiểu '
                .number_format($promotion->minimum_order_amount, 0, ',', '.').' đ';
        }

        $totalUsage = max((int) $promotion->usage_count, (int) $promotion->usages_count);

        if ($promotion->usage_limit !== null && $totalUsage >= $promotion->usage_limit) {
            return 'Voucher đã hết lượt sử dụng';
        }

        if ($promotion->per_user_limit !== null && (int) $promotion->user_usage_count >= $promotion->per_user_limit) {
            return 'Bạn đã sử dụng hết số lượt cho voucher này';
        }

        $allowedUserIds = collect($promotion->scope['user_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id);

        if ($allowedUserIds->isNotEmpty() && ! $allowedUserIds->contains($user->id)) {
            return 'Voucher này không được cấp cho tài khoản của bạn';
        }

        return null;
    }

    private function throwCodeError(string $message): never
    {
        throw ValidationException::withMessages([
            'code' => [$message],
        ]);
    }
}
