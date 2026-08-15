<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Repositories\ReviewRepository;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
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

        if ($review->product_id === null || $review->order_item_id === null
            || $review->service_id !== null || $review->appointment_id !== null) {
            throw ValidationException::withMessages([
                'review' => ["\u{0110}\u{00E1}nh gi\u{00E1} kh\u{00F4}ng thu\u{1ED9}c s\u{1EA3}n ph\u{1EA9}m"],
            ]);
        }

        return $this->reviews->updateCustomerReview($review, $data);
    }

    /**
     * @param  array{appointment_id: int, rating: int, title?: string|null, comment?: string|null}  $data
     */
    public function createServiceReview(User $user, array $data): ?Review
    {
        Gate::forUser($user)->authorize('create', Review::class);

        try {
            return $this->reviews->transaction(function () use ($user, $data): ?Review {
                $appointment = $this->reviews->lockOwnedAppointment(
                    (int) $data['appointment_id'],
                    $user->id,
                );

                if ($appointment === null) {
                    return null;
                }

                if ($appointment->status !== AppointmentStatus::Completed) {
                    throw ValidationException::withMessages([
                        'appointment_id' => ["L\u{1ECB}ch h\u{1EB9}n ch\u{01B0}a \u{0111}\u{1EE7} \u{0111}i\u{1EC1}u ki\u{1EC7}n \u{0111}\u{00E1}nh gi\u{00E1}"],
                    ]);
                }

                if ($appointment->service === null) {
                    throw ValidationException::withMessages([
                        'appointment_id' => ["Kh\u{00F4}ng th\u{1EC3} x\u{00E1}c \u{0111}\u{1ECB}nh d\u{1ECB}ch v\u{1EE5} t\u{1EEB} l\u{1ECB}ch h\u{1EB9}n"],
                    ]);
                }

                if ($this->reviews->appointmentHasReview($appointment->id)) {
                    throw ValidationException::withMessages([
                        'appointment_id' => ["L\u{1ECB}ch h\u{1EB9}n n\u{00E0}y \u{0111}\u{00E3} \u{0111}\u{01B0}\u{1EE3}c \u{0111}\u{00E1}nh gi\u{00E1}"],
                    ]);
                }

                return $this->reviews->createCustomerReview([
                    'user_id' => $user->id,
                    'product_id' => null,
                    'service_id' => $appointment->service_id,
                    'appointment_id' => $appointment->id,
                    'rating' => (int) $data['rating'],
                    'title' => $data['title'] ?? null,
                    'comment' => $data['comment'] ?? null,
                    'is_visible' => true,
                ]);
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'appointment_id' => ["L\u{1ECB}ch h\u{1EB9}n n\u{00E0}y \u{0111}\u{00E3} \u{0111}\u{01B0}\u{1EE3}c \u{0111}\u{00E1}nh gi\u{00E1}"],
            ]);
        }
    }

    /** @param array{rating?: int, title?: string|null, comment?: string|null} $data */
    public function updateServiceReview(User $user, int $reviewId, array $data): ?Review
    {
        $review = $this->reviews->findForCustomerUpdate($reviewId);

        if ($review === null) {
            return null;
        }

        Gate::forUser($user)->authorize('update', $review);

        if ($review->service_id === null || $review->appointment_id === null
            || $review->product_id !== null || $review->order_item_id !== null) {
            throw ValidationException::withMessages([
                'review' => ["\u{0110}\u{00E1}nh gi\u{00E1} kh\u{00F4}ng thu\u{1ED9}c d\u{1ECB}ch v\u{1EE5}"],
            ]);
        }

        return $this->reviews->updateCustomerReview($review, $data);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{service: Service, reviews: LengthAwarePaginator, summary: array<string, mixed>}|null
     */
    public function getActiveServiceReviews(string $identifier, array $filters): ?array
    {
        $service = $this->reviews->findActiveServiceForReviews($identifier);

        if ($service === null) {
            return null;
        }

        return [
            'service' => $service,
            'reviews' => $this->reviews->paginateVisibleServiceReviews($service, $filters),
            'summary' => $this->reviews->visibleServiceReviewSummary($service),
        ];
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
