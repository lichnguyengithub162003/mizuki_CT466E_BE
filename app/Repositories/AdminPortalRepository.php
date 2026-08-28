<?php

namespace App\Repositories;

use App\Enums\AppointmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminPortalRepository
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function dashboard(User $actor, array $filters): array
    {
        [$from, $to] = $this->dateRange($filters);
        $branchId = $this->effectiveBranchId($actor, $filters);

        $payments = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', PaymentStatus::Paid->value)
            ->whereNotNull('payments.order_id')
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->when($from !== null, fn ($query) => $query->where('payments.paid_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('payments.paid_at', '<=', $to));

        $orders = Order::query()
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to));
        $appointments = Appointment::query()
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($from !== null, fn (Builder $query) => $query->where('starts_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('starts_at', '<=', $to));
        $refunds = Refund::query()->where('status', 'requested')
            ->whereHas('order', fn (Builder $query) => $query->when(
                $branchId !== null,
                fn (Builder $branchQuery) => $branchQuery->where('branch_id', $branchId),
            ));

        $customerQuery = User::query()->where('role', UserRole::Customer->value);
        if ($branchId !== null) {
            $customerQuery->where(function (Builder $query) use ($branchId): void {
                $query->whereHas('orders', fn (Builder $orders) => $orders->where('branch_id', $branchId))
                    ->orWhereHas('appointments', fn (Builder $appointments) => $appointments->where('branch_id', $branchId));
            });
        }

        $series = (clone $payments)
            ->selectRaw('DATE(payments.paid_at) as date, SUM(payments.amount) as revenue, COUNT(DISTINCT orders.id) as orders')
            ->groupByRaw('DATE(payments.paid_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->date,
                'revenue' => (int) $row->revenue,
                'orders' => (int) $row->orders,
            ])->all();

        $methods = (clone $payments)
            ->selectRaw('payments.method, COUNT(*) as aggregate_count, SUM(payments.amount) as aggregate_amount')
            ->groupBy('payments.method')
            ->orderBy('payments.method')
            ->get()
            ->map(fn ($row): array => [
                'method' => (string) $row->getRawOriginal('method'),
                'count' => (int) $row->aggregate_count,
                'amount' => (int) $row->aggregate_amount,
            ])->all();

        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->where('payments.status', PaymentStatus::Paid->value)
            ->when($branchId !== null, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->when($from !== null, fn ($query) => $query->where('payments.paid_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('payments.paid_at', '<=', $to))
            ->selectRaw('products.id as product_id, order_items.product_name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as revenue')
            ->groupBy('products.id', 'order_items.product_name')
            ->orderByDesc('quantity')->limit(10)->get();
        $productIds = $topProducts->pluck('product_id')->filter()->map(fn ($id): int => (int) $id);
        $images = DB::table('product_images')->whereIn('product_id', $productIds)
            ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')
            ->get()->unique('product_id')->keyBy('product_id');

        return [
            'summary' => [
                'revenue' => (int) (clone $payments)->sum('payments.amount'),
                'orders' => (clone $orders)->count(),
                'pending_orders' => (clone $orders)->where('status', OrderStatus::Pending->value)->count(),
                'appointments' => (clone $appointments)->count(),
                'pending_refunds' => $refunds->count(),
                'customers' => $customerQuery->count(),
            ],
            'revenue_series' => $series,
            'payment_methods' => $methods,
            'top_products' => $topProducts->map(fn ($row): array => [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'quantity' => (int) $row->quantity,
                'revenue' => (int) $row->revenue,
                'image_url' => $row->product_id === null ? null : ($images->get($row->product_id)?->image_url),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, User> */
    public function customers(User $actor, array $filters): LengthAwarePaginator
    {
        $query = User::query()->where('role', UserRole::Customer->value);
        $this->scopeCustomers($query, $actor);

        return $query
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            })
            ->withCount(['orders', 'appointments'])
            ->when(($filters['sort'] ?? 'newest') === 'name', fn (Builder $query) => $query->orderBy('name'), fn (Builder $query) => $query->orderByDesc('created_at'))
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function customer(User $actor, int $id): ?User
    {
        $query = User::query()->where('role', UserRole::Customer->value)->whereKey($id);
        $this->scopeCustomers($query, $actor);

        return $query->with([
            'wallet:id,user_id,balance', 'skinProfile',
            'orders' => fn ($query) => $query->with('payment')->latest()->limit(5),
            'appointments' => fn ($query) => $query->with('branch:id,name')->latest('starts_at')->limit(5),
        ])->withCount(['orders', 'appointments'])->first();
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, Product> */
    public function products(array $filters): LengthAwarePaginator
    {
        return Product::query()->with(['brand:id,name,slug', 'category:id,name,slug'])
            ->with(['images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')])
            ->withCount('variants')
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query->where('name', 'like', '%'.trim((string) $filters['search']).'%'))
            ->when(isset($filters['category_id']), fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['brand_id']), fn (Builder $query) => $query->where('brand_id', $filters['brand_id']))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->latest()->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function product(int $id): ?Product
    {
        return Product::query()->whereKey($id)->with([
            'brand', 'category',
            'images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
            'variants' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'variants.inventories.branch:id,name',
        ])->first();
    }

    /** @param array<string, mixed> $data */
    public function saveProduct(?Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $variants = $data['variants'] ?? null;
            $images = $data['images'] ?? null;
            unset($data['variants'], $data['images']);
            $product ??= new Product;
            $product->fill($data)->save();

            if (is_array($variants)) {
                foreach ($variants as $variantData) {
                    $variantId = $variantData['id'] ?? null;
                    unset($variantData['id']);
                    $variant = $variantId === null
                        ? $product->variants()->make()
                        : $product->variants()->whereKey($variantId)->firstOrFail();
                    $variant->fill($variantData)->save();
                }
            }
            if (is_array($images)) {
                $product->images()->delete();
                $product->images()->createMany($images);
            }

            return $this->product($product->id) ?? $product->refresh();
        });
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, Category> */
    public function categories(array $filters): LengthAwarePaginator
    {
        return Category::query()->with('parent:id,name,slug')->withCount(['children', 'products'])
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query->where('name', 'like', '%'.trim((string) $filters['search']).'%'))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('sort_order')->orderBy('name')->paginate((int) ($filters['per_page'] ?? 100));
    }

    public function category(int $id): ?Category
    {
        return Category::query()->with(['parent:id,name,slug', 'children' => fn ($query) => $query->orderBy('sort_order')])
            ->withCount('products')->find($id);
    }

    /** @param array<string, mixed> $data */
    public function saveCategory(?Category $category, array $data): Category
    {
        $category ??= new Category;
        $category->fill($data)->save();

        return $this->category($category->id) ?? $category->refresh();
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, Brand> */
    public function brands(array $filters): LengthAwarePaginator
    {
        return Brand::query()->withCount('products')
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query->where('name', 'like', '%'.trim((string) $filters['search']).'%'))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function brand(int $id): ?Brand
    {
        return Brand::query()->withCount('products')->find($id);
    }

    /** @param array<string, mixed> $data */
    public function saveBrand(?Brand $brand, array $data): Brand
    {
        $brand ??= new Brand;
        $brand->fill($data)->save();

        return $this->brand($brand->id) ?? $brand->refresh();
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, BranchInventory> */
    public function inventory(User $actor, array $filters): LengthAwarePaginator
    {
        $branchId = $this->effectiveBranchId($actor, $filters);

        return BranchInventory::query()->with(['branch:id,code,name', 'productVariant.product:id,name'])
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->whereHas('productVariant', fn (Builder $variant) => $variant
                    ->where('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")));
            })
            ->when(($filters['low_stock'] ?? false), fn (Builder $query) => $query->whereColumn('quantity', '<=', 'reorder_level'))
            ->orderBy('branch_id')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function inventoryItem(User $actor, int $id, bool $lock = false): ?BranchInventory
    {
        $query = BranchInventory::query()->with(['branch:id,code,name', 'productVariant.product:id,name'])->whereKey($id);
        if ($actor->role === UserRole::BranchManager) {
            $query->where('branch_id', $actor->branch_id ?? 0);
        }

        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /** @return LengthAwarePaginator<int, InventoryTransaction> */
    public function inventoryTransactions(User $actor, int $id, int $perPage): ?LengthAwarePaginator
    {
        $inventory = $this->inventoryItem($actor, $id);
        if ($inventory === null) {
            return null;
        }

        return $inventory->transactions()->with('performedBy:id,name')->latest('created_at')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function adjustInventory(User $actor, int $id, array $data): ?BranchInventory
    {
        return DB::transaction(function () use ($actor, $id, $data): ?BranchInventory {
            $inventory = $this->inventoryItem($actor, $id, true);
            if ($inventory === null) {
                return null;
            }
            $quantityAfter = $inventory->quantity + (int) $data['quantity_delta'];
            if ($quantityAfter < 0 || $quantityAfter < $inventory->reserved_quantity) {
                throw new \DomainException('Điều chỉnh sẽ làm tồn kho khả dụng bị âm');
            }

            $inventory->quantity = $quantityAfter;
            $inventory->save();
            $inventory->transactions()->create([
                'transaction_number' => 'ADJ-'.now()->format('YmdHis').'-'.strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8)),
                'performed_by_user_id' => $actor->id,
                'type' => 'adjustment',
                'quantity_delta' => (int) $data['quantity_delta'],
                'reserved_quantity_delta' => 0,
                'quantity_after' => $quantityAfter,
                'reserved_quantity_after' => $inventory->reserved_quantity,
                'note' => $data['reason'],
            ]);

            return $this->inventoryItem($actor, $id);
        });
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, Branch> */
    public function branches(User $actor, array $filters): LengthAwarePaginator
    {
        return Branch::query()->with(['businessHours' => fn ($query) => $query->orderBy('weekday')])
            ->when($actor->role === UserRole::BranchManager, fn (Builder $query) => $query->whereKey($actor->branch_id ?? 0))
            ->when(filled($filters['search'] ?? null), fn (Builder $query) => $query->where('name', 'like', '%'.trim((string) $filters['search']).'%'))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function branch(User $actor, int $id): ?Branch
    {
        return Branch::query()->when($actor->role === UserRole::BranchManager, fn (Builder $query) => $query->whereKey($actor->branch_id ?? 0))
            ->whereKey($id)->with(['businessHours' => fn ($query) => $query->orderBy('weekday')])->first();
    }

    /** @param array<string, mixed> $data */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data): Branch {
            $hours = $data['business_hours'] ?? null;
            unset($data['business_hours']);
            $branch->fill($data)->save();
            if (is_array($hours)) {
                foreach ($hours as $hour) {
                    $branch->businessHours()->updateOrCreate(['weekday' => $hour['weekday']], $hour);
                }
            }

            return $branch->refresh()->load(['businessHours' => fn ($query) => $query->orderBy('weekday')]);
        });
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, User> */
    public function staff(User $actor, array $filters): LengthAwarePaginator
    {
        return User::query()->where('role', '!=', UserRole::Customer->value)->with('branch:id,code,name')
            ->when($actor->role === UserRole::BranchManager, fn (Builder $query) => $query->where('branch_id', $actor->branch_id ?? 0)->whereIn('role', [UserRole::Cashier->value, UserRole::Technician->value]))
            ->when(isset($filters['branch_id']) && $actor->role === UserRole::SuperAdmin, fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            })->latest()->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function staffMember(User $actor, int $id): ?User
    {
        return User::query()->where('role', '!=', UserRole::Customer->value)
            ->when($actor->role === UserRole::BranchManager, fn (Builder $query) => $query->where('branch_id', $actor->branch_id ?? 0)->whereIn('role', [UserRole::Cashier->value, UserRole::Technician->value]))
            ->with('branch:id,code,name')->find($id);
    }

    /** @param array<string, mixed> $data */
    public function saveStaff(?User $staff, array $data): User
    {
        $staff ??= new User;
        $staff->fill($data)->save();

        return $staff->refresh()->load('branch:id,code,name');
    }

    /** @param array<string, mixed> $filters @return LengthAwarePaginator<int, Review> */
    public function reviews(User $actor, array $filters): LengthAwarePaginator
    {
        $query = Review::query()->with(['user:id,name,email', 'product:id,name', 'service:id,name', 'moderatedBy:id,name']);
        $this->scopeReviews($query, $actor);

        return $query
            ->when(($filters['type'] ?? null) === 'product', fn (Builder $query) => $query->whereNotNull('product_id'))
            ->when(($filters['type'] ?? null) === 'service', fn (Builder $query) => $query->whereNotNull('service_id'))
            ->when(isset($filters['rating']), fn (Builder $query) => $query->where('rating', $filters['rating']))
            ->when(($filters['visibility'] ?? null) === 'visible', fn (Builder $query) => $query->where('is_visible', true))
            ->when(($filters['visibility'] ?? null) === 'hidden', fn (Builder $query) => $query->where('is_visible', false))
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(fn (Builder $nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('comment', 'like', "%{$search}%")->orWhere('source_author_name', 'like', "%{$search}%"));
            })->latest()->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function review(User $actor, int $id): ?Review
    {
        $query = Review::query()->whereKey($id);
        $this->scopeReviews($query, $actor);

        return $query->with(['user:id,name,email', 'product:id,name', 'service:id,name', 'productVariant:id,name,sku', 'moderatedBy:id,name'])->first();
    }

    /** @param array<string, mixed> $data */
    public function moderateReview(Review $review, User $actor, array $data): Review
    {
        $review->fill($data + ['moderated_by_user_id' => $actor->id, 'moderated_at' => now()])->save();

        return $review->refresh()->load(['user:id,name,email', 'product:id,name', 'service:id,name', 'productVariant:id,name,sku', 'moderatedBy:id,name']);
    }

    /** @param Builder<User> $query */
    private function scopeCustomers(Builder $query, User $actor): void
    {
        if ($actor->role !== UserRole::BranchManager) {
            return;
        }
        $branchId = $actor->branch_id ?? 0;
        $query->where(fn (Builder $nested) => $nested
            ->whereHas('orders', fn (Builder $orders) => $orders->where('branch_id', $branchId))
            ->orWhereHas('appointments', fn (Builder $appointments) => $appointments->where('branch_id', $branchId)));
    }

    /** @param Builder<Review> $query */
    private function scopeReviews(Builder $query, User $actor): void
    {
        if ($actor->role !== UserRole::BranchManager) {
            return;
        }
        $branchId = $actor->branch_id ?? 0;
        $query->where(function (Builder $nested) use ($branchId): void {
            $nested->whereHas('orderItem.order', fn (Builder $orders) => $orders->where('branch_id', $branchId))
                ->orWhereHas('appointment', fn (Builder $appointments) => $appointments->where('branch_id', $branchId));
        });
    }

    /** @param array<string, mixed> $filters */
    private function effectiveBranchId(User $actor, array $filters): ?int
    {
        if ($actor->role === UserRole::BranchManager) {
            return $actor->branch_id ?? 0;
        }

        return isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
    }

    /** @param array<string, mixed> $filters @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function dateRange(array $filters): array
    {
        return [
            isset($filters['date_from']) ? CarbonImmutable::parse($filters['date_from'])->startOfDay() : null,
            isset($filters['date_to']) ? CarbonImmutable::parse($filters['date_to'])->endOfDay() : null,
        ];
    }
}
