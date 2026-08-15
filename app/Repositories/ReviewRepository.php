<?php

namespace App\Repositories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\Service;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function lockOwnedAppointment(int $appointmentId, int $userId): ?Appointment
    {
        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($appointment === null) {
            return null;
        }

        $appointment->load([
            'service' => fn (BelongsTo $service): BelongsTo => $service->withTrashed(),
        ]);

        return $appointment;
    }

    public function appointmentHasReview(int $appointmentId): bool
    {
        return $this->query()
            ->withTrashed()
            ->where('appointment_id', $appointmentId)
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

    public function findActiveServiceForReviews(string $identifier): ?Service
    {
        return Service::query()
            ->select(['id', 'name', 'slug'])
            ->where(function (Builder $query) use ($identifier): void {
                $query->where('slug', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Review>
     */
    public function paginateVisibleServiceReviews(Service $service, array $filters): LengthAwarePaginator
    {
        $query = $this->query()
            ->select([
                'reviews.id',
                'reviews.user_id',
                'reviews.service_id',
                'reviews.appointment_id',
                'reviews.rating',
                'reviews.title',
                'reviews.comment',
                'reviews.created_at',
            ])
            ->where('reviews.service_id', $service->id)
            ->whereNotNull('reviews.appointment_id')
            ->whereNull('reviews.source')
            ->where('reviews.is_visible', true)
            ->with('user:id,name,avatar')
            ->withExists([
                'appointment as internal_verified_service' => fn (Builder $appointment): Builder => $this
                    ->applyVerifiedServiceConstraints($appointment),
            ]);

        if (isset($filters['rating'])) {
            $query->where('reviews.rating', (int) $filters['rating']);
        }

        if (array_key_exists('verified_service', $filters)) {
            $method = $filters['verified_service'] ? 'whereHas' : 'whereDoesntHave';
            $query->{$method}(
                'appointment',
                fn (Builder $appointment): Builder => $this->applyVerifiedServiceConstraints($appointment),
            );
        }

        return $query
            ->orderByDesc('reviews.created_at')
            ->orderByDesc('reviews.id')
            ->paginate((int) ($filters['per_page'] ?? 10));
    }

    /**
     * @return array{average_rating: float, total_reviews: int, rating_distribution: object, verified_service_reviews_count: int}
     */
    public function visibleServiceReviewSummary(Service $service): array
    {
        $base = $this->query()
            ->where('service_id', $service->id)
            ->whereNotNull('appointment_id')
            ->whereNull('source')
            ->where('is_visible', true);
        $summary = (clone $base)
            ->selectRaw('COUNT(*) as total_reviews')
            ->selectRaw('COALESCE(AVG(rating), 0) as average_rating')
            ->selectRaw('SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5')
            ->selectRaw('SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4')
            ->selectRaw('SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3')
            ->selectRaw('SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2')
            ->selectRaw('SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1')
            ->first();
        $verifiedCount = (clone $base)
            ->whereHas(
                'appointment',
                fn (Builder $appointment): Builder => $this->applyVerifiedServiceConstraints($appointment),
            )
            ->count();

        return [
            'average_rating' => round((float) ($summary?->average_rating ?? 0), 1),
            'total_reviews' => (int) ($summary?->total_reviews ?? 0),
            'rating_distribution' => (object) [
                5 => (int) ($summary?->rating_5 ?? 0),
                4 => (int) ($summary?->rating_4 ?? 0),
                3 => (int) ($summary?->rating_3 ?? 0),
                2 => (int) ($summary?->rating_2 ?? 0),
                1 => (int) ($summary?->rating_1 ?? 0),
            ],
            'verified_service_reviews_count' => $verifiedCount,
        ];
    }

    /** @param Builder<Appointment> $query */
    private function applyVerifiedServiceConstraints(Builder $query): Builder
    {
        return $query
            ->whereColumn('appointments.user_id', 'reviews.user_id')
            ->whereColumn('appointments.service_id', 'reviews.service_id')
            ->where('appointments.status', AppointmentStatus::Completed->value);
    }

    private function loadCustomerResponse(Review $review): Review
    {
        if ($review->service_id !== null) {
            return $review->load([
                'service' => fn (BelongsTo $service): BelongsTo => $service->withTrashed(),
                'appointment',
            ]);
        }

        return $review->load([
            'product' => fn (BelongsTo $product): BelongsTo => $product->withTrashed(),
            'productVariant' => fn (BelongsTo $variant): BelongsTo => $variant->withTrashed(),
        ]);
    }
}
