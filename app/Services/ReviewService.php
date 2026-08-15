<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Repositories\ReviewRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewService extends BaseService
{
    public function __construct(
        private readonly ReviewRepository $reviews,
    ) {}

    /**
     * @param  array{order_item_id: int, rating: int, title?: string|null, comment?: string|null}  $data
     */
    public function create(User $user, array $data): ?Review
    {
        Gate::forUser($user)->authorize('create', Review::class);

        try {
            return $this->reviews->transaction(function () use ($user, $data): ?Review {
                $item = $this->reviews->lockOwnedOrderItem((int) $data['order_item_id'], $user->id);

                if ($item === null) {
                    return null;
                }

                $this->ensureEligiblePurchase($item->order);
                $variant = $item->productVariant;
                $product = $variant?->product;

                if ($variant === null || $product === null) {
                    throw ValidationException::withMessages([
                        'order_item_id' => ['Không thể xác định sản phẩm từ đơn hàng'],
                    ]);
                }

                if ($this->reviews->orderItemHasReview($item->id)) {
                    throw ValidationException::withMessages([
                        'order_item_id' => ['Sản phẩm trong đơn hàng này đã được đánh giá'],
                    ]);
                }

                if ($this->reviews->userHasProductReview($user->id, $product->id)) {
                    throw ValidationException::withMessages([
                        'order_item_id' => ['Bạn đã đánh giá sản phẩm này'],
                    ]);
                }

                return $this->reviews->createCustomerReview([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'order_item_id' => $item->id,
                    'rating' => (int) $data['rating'],
                    'title' => $data['title'] ?? null,
                    'comment' => $data['comment'] ?? null,
                    'is_visible' => true,
                ]);
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'order_item_id' => ['Sản phẩm đã được đánh giá'],
            ]);
        }
    }

    /** @param array{rating?: int, title?: string|null, comment?: string|null} $data */
    public function update(User $user, int $reviewId, array $data): ?Review
    {
        $review = $this->reviews->findForCustomerUpdate($reviewId);

        if ($review === null) {
            return null;
        }

        Gate::forUser($user)->authorize('update', $review);

        return $this->reviews->updateCustomerReview($review, $data);
    }

    private function ensureEligiblePurchase(Order $order): void
    {
        $eligible = $order->channel === 'counter'
            ? $order->status === OrderStatus::Confirmed
            : $order->status === OrderStatus::Delivered;

        if (! $eligible) {
            throw ValidationException::withMessages([
                'order_item_id' => ['Đơn hàng chưa đủ điều kiện đánh giá'],
            ]);
        }
    }
}
