<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<Review> */
class ReviewRepository extends BaseRepository
{
    public function __construct(Review $model)
    {
        parent::__construct($model);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function lockOwnedOrderItem(int $orderItemId, int $userId): ?OrderItem
    {
        $candidate = OrderItem::query()
            ->select(['id', 'order_id'])
            ->find($orderItemId);

        if ($candidate === null) {
            return null;
        }

        $order = Order::query()
            ->whereKey($candidate->order_id)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            return null;
        }

        $item = OrderItem::query()
            ->whereKey($candidate->id)
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($item === null) {
            return null;
        }

        $item->setRelation('order', $order);
        $item->load([
            'productVariant' => fn (BelongsTo $variant): BelongsTo => $variant->withTrashed(),
            'productVariant.product' => fn (BelongsTo $product): BelongsTo => $product->withTrashed(),
        ]);

        return $item;
    }

    public function orderItemHasReview(int $orderItemId): bool
    {
        return $this->query()
            ->withTrashed()
            ->where('order_item_id', $orderItemId)
            ->exists();
    }

    public function userHasProductReview(int $userId, int $productId): bool
    {
        return $this->query()
            ->withTrashed()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function createCustomerReview(array $attributes): Review
    {
        /** @var Review $review */
        $review = $this->query()->create($attributes);

        return $this->loadCustomerResponse($review);
    }

    public function findForCustomerUpdate(int $reviewId): ?Review
    {
        /** @var Review|null $review */
        $review = $this->query()->find($reviewId);

        return $review;
    }

    /** @param array<string, mixed> $attributes */
    public function updateCustomerReview(Review $review, array $attributes): Review
    {
        $review->update($attributes);

        return $this->loadCustomerResponse($review->refresh());
    }

    private function loadCustomerResponse(Review $review): Review
    {
        return $review->load([
            'product' => fn (BelongsTo $product): BelongsTo => $product->withTrashed(),
            'productVariant' => fn (BelongsTo $variant): BelongsTo => $variant->withTrashed(),
        ]);
    }
}
