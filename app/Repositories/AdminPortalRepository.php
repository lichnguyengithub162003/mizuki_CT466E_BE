<?php

namespace App\Repositories;

use App\Enums\AppointmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StaffEmploymentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Review;
use App\Models\StaffAssignment;
use App\Models\StaffLifecycleEvent;
use App\Models\User;
use App\Support\MediaUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminPortalRepository
{
    public function __construct(private readonly MediaUrl $mediaUrl)
    {
        //
    }

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
                'image_url' => $row->product_id === null ? null : $this->mediaUrl->resolve($images->get($row->product_id)?->image_url),
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
                'transaction_number' => 'ADJ-'.now()->format('YmdHis').'-'.strtoupper(substr((string) Str::uuid(), 0, 8)),
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
            ->when($actor->role === UserRole::BranchManager, fn (Builder $query) => $query->where('branch_id', $actor->branch_id ?? 0)->where('role', '!=', UserRole::SuperAdmin->value))
            ->when(isset($filters['branch_id']) && $actor->role === UserRole::SuperAdmin, fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['role']), fn (Builder $query) => $query->where('role', $filters['role']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('employment_status', $filters['status']))
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(fn (Builder $nested) => $nested->where('staff_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('job_title', 'like', "%{$search}%"));
            })->latest()->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function staffMember(User $actor, int $id): ?User
    {
        return $this->staffVisibilityQuery($actor)
            ->with($this->staffDetailRelations())
            ->find($id);
    }

    /** @param array<string, mixed> $data */
    public function saveStaff(?User $staff, array $data): User
    {
        if (array_key_exists('status', $data)) {
            $data['employment_status'] = StaffEmploymentStatus::from((string) $data['status']);
            unset($data['status']);
        }

        return DB::transaction(function () use ($staff, $data): User {
            $creating = $staff === null;
            $staff = $creating
                ? new User
                : User::query()->lockForUpdate()->findOrFail($staff->id);
            $actor = auth()->user();
            $actorId = $actor instanceof User ? $actor->id : null;
            $before = $creating ? null : $this->staffState($staff);
            $targetRole = isset($data['role']) ? UserRole::from((string) $data['role']) : ($staff->role ?? UserRole::Customer);
            $targetStatus = isset($data['employment_status'])
                ? ($data['employment_status'] instanceof StaffEmploymentStatus
                    ? $data['employment_status']
                    : StaffEmploymentStatus::from((string) $data['employment_status']))
                : ($staff->employment_status ?? StaffEmploymentStatus::Working);
            $targetBranchId = array_key_exists('branch_id', $data) ? $data['branch_id'] : $staff->branch_id;
            $this->assertSensibleAssignmentTarget(
                $targetRole,
                $targetBranchId,
                $this->defaultWorkArea($targetRole),
            );

            if (! $creating) {
                $this->assertPreservesUsableSuperAdmin($staff, $targetRole, $targetStatus, false);
                if ($this->assignmentScopeChanges($before, $data)
                    || ($staff->employment_status === StaffEmploymentStatus::Working && $targetStatus === StaffEmploymentStatus::Left)) {
                    $this->assertNoStaffBlockers($staff);
                }
            }

            $staff->fill($data)->save();

            if ($creating) {
                $assignment = $staff->employment_status === StaffEmploymentStatus::Working
                    ? $this->createStaffAssignment($staff, $actorId, $staff->created_at, $this->defaultWorkArea($staff->role), 'Tạo tài khoản nhân viên')
                    : null;
                $this->recordStaffEvent($staff, StaffLifecycleEvent::ACCOUNT_CREATED, $actorId, $assignment?->id, [
                    'state' => $this->staffState($staff),
                ], 'Tạo tài khoản nhân viên');
            } else {
                $this->synchronizeStaffHistoryAfterMutation($staff, $before, $actorId);
            }

            return $this->loadStaffDetail($staff->refresh());
        });
    }

    /** @param array<string, mixed> $target */
    public function staffAssignmentPreflight(User $actor, int $id, array $target): ?array
    {
        $staff = $this->staffVisibilityQuery($actor)->find($id);
        if ($staff === null) {
            return null;
        }

        $this->assertCanChangeAssignment($actor, $staff, $target);

        return $this->staffBlockers($staff);
    }

    /** @param array<string, mixed> $data */
    public function changeStaffAssignment(User $actor, int $id, array $data): ?User
    {
        $visible = $this->staffVisibilityQuery($actor)->find($id);
        if ($visible === null) {
            return null;
        }

        return DB::transaction(function () use ($actor, $id, $data): User {
            $staff = User::query()->lockForUpdate()->findOrFail($id);
            $this->assertCanChangeAssignment($actor, $staff, $data);
            if ($staff->employment_status !== StaffEmploymentStatus::Working) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ có thể thay đổi phân công cho nhân viên đang làm việc'],
                ]);
            }

            $current = $this->ensureCurrentStaffAssignment($staff, $actor->id);
            $targetRole = isset($data['role']) ? UserRole::from((string) $data['role']) : $staff->role;
            $target = [
                'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : $staff->branch_id,
                'role' => $targetRole,
                'job_title' => array_key_exists('job_title', $data) ? $data['job_title'] : $staff->job_title,
                'work_area' => array_key_exists('work_area', $data)
                    ? $data['work_area']
                    : ($targetRole === $staff->role ? $current?->work_area : $this->defaultWorkArea($targetRole)),
            ];
            $this->assertSensibleAssignmentTarget($target['role'], $target['branch_id'], $target['work_area']);

            $before = [
                'branch_id' => $staff->branch_id,
                'role' => $staff->role->value,
                'job_title' => $staff->job_title,
                'work_area' => $current?->work_area,
            ];
            $after = [
                'branch_id' => $target['branch_id'],
                'role' => $target['role']->value,
                'job_title' => $target['job_title'],
                'work_area' => $target['work_area'],
            ];
            if ($before === $after) {
                throw ValidationException::withMessages([
                    'assignment' => ['Phân công mới không có thay đổi so với phân công hiện tại'],
                ]);
            }

            $this->assertPreservesUsableSuperAdmin($staff, $target['role'], $staff->employment_status, false);
            $this->assertNoStaffBlockers($staff);
            $effectiveFrom = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : CarbonImmutable::now();
            if ($current !== null && $effectiveFrom->lt($current->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_from' => ['Thời điểm áp dụng không được trước phân công hiện tại'],
                ]);
            }

            $this->closeCurrentStaffAssignment($staff, $effectiveFrom, $current);
            $staff->forceFill([
                'branch_id' => $target['branch_id'],
                'role' => $target['role'],
                'job_title' => $target['job_title'],
            ])->save();
            $assignment = $this->createStaffAssignment(
                $staff,
                $actor->id,
                $effectiveFrom,
                $target['work_area'],
                $data['reason'] ?? null,
            );
            $this->recordAssignmentEvents($staff, $actor->id, $assignment->id, $before, $after, $data['reason'] ?? null);

            return $this->loadStaffDetail($staff->refresh());
        });
    }

    public function changeStaffEmploymentStatus(User $actor, int $id, string $status): ?User
    {
        $visible = $this->staffVisibilityQuery($actor)->find($id);
        if ($visible === null) {
            return null;
        }

        return DB::transaction(function () use ($actor, $id, $status): User {
            $staff = User::query()->lockForUpdate()->findOrFail($id);
            $this->assertCanManageStaff($actor, $staff);
            $target = StaffEmploymentStatus::from($status);
            if ($staff->employment_status === $target) {
                throw ValidationException::withMessages([
                    'status' => ['Trạng thái làm việc mới trùng với trạng thái hiện tại'],
                ]);
            }

            $this->assertPreservesUsableSuperAdmin($staff, $staff->role, $target, false);
            if ($target === StaffEmploymentStatus::Left) {
                $this->assertNoStaffBlockers($staff);
            }

            $before = $staff->employment_status;
            $staff->forceFill(['employment_status' => $target])->save();
            if ($target === StaffEmploymentStatus::Left) {
                $this->closeCurrentStaffAssignment($staff, CarbonImmutable::now());
            } else {
                $this->ensureCurrentStaffAssignment($staff, $actor->id, 'Nhân viên quay lại làm việc');
            }
            $this->recordStaffEvent($staff, StaffLifecycleEvent::EMPLOYMENT_STATUS_CHANGED, $actor->id, null, [
                'from' => $before->value,
                'to' => $target->value,
            ], "Thay đổi trạng thái từ {$before->label()} sang {$target->label()}");

            return $this->loadStaffDetail($staff->refresh());
        });
    }

    public function trashStaff(User $actor, int $id): ?User
    {
        $visible = $this->staffVisibilityQuery($actor)->find($id);
        if ($visible === null) {
            return null;
        }

        return DB::transaction(function () use ($actor, $id): User {
            $staff = User::query()->lockForUpdate()->findOrFail($id);
            $this->assertCanManageStaff($actor, $staff);
            $this->assertPreservesUsableSuperAdmin($staff, $staff->role, $staff->employment_status, true);
            $this->assertNoStaffBlockers($staff);
            $this->closeCurrentStaffAssignment($staff, CarbonImmutable::now());
            $this->recordStaffEvent($staff, StaffLifecycleEvent::TRASHED, $actor->id, null, [], 'Chuyển nhân viên vào thùng rác');
            $staff->delete();

            return $this->loadStaffDetail($staff);
        });
    }

    public function restoreStaff(User $actor, int $id): ?User
    {
        $staff = $this->staffVisibilityQuery($actor, true)->onlyTrashed()->find($id);
        if ($staff === null) {
            return null;
        }

        return DB::transaction(function () use ($actor, $staff): User {
            $staff = User::withTrashed()->lockForUpdate()->findOrFail($staff->id);
            $this->assertCanManageStaff($actor, $staff);
            $staff->restore();
            if ($staff->employment_status === StaffEmploymentStatus::Working) {
                $this->ensureCurrentStaffAssignment($staff, $actor->id, 'Khôi phục tài khoản nhân viên');
            }
            $this->recordStaffEvent($staff, StaffLifecycleEvent::RESTORED, $actor->id, null, [], 'Khôi phục tài khoản nhân viên');

            return $this->loadStaffDetail($staff->refresh());
        });
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

    private function staffVisibilityQuery(User $actor, bool $withTrashed = false): Builder
    {
        $query = User::query();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->where('role', '!=', UserRole::Customer->value)
            ->when(
                $actor->role === UserRole::BranchManager,
                fn (Builder $query) => $query
                    ->where('branch_id', $actor->branch_id ?? 0)
                    ->where('role', '!=', UserRole::SuperAdmin->value),
            );
    }

    /** @return array<int, string|Closure> */
    private function staffDetailRelations(): array
    {
        return [
            'branch:id,code,name',
            'currentAssignment.branch:id,code,name',
            'staffAssignments' => fn ($query) => $query->with('branch:id,code,name')->orderByDesc('effective_from')->orderByDesc('id'),
            'staffLifecycleEvents' => fn ($query) => $query->with('actor:id,name')->latest('occurred_at')->latest('id'),
        ];
    }

    private function loadStaffDetail(User $staff): User
    {
        return $staff->load($this->staffDetailRelations());
    }

    /** @return array{branch_id: int|null, role: string, job_title: string|null, employment_status: string} */
    private function staffState(User $staff): array
    {
        return [
            'branch_id' => $staff->branch_id,
            'role' => $staff->role->value,
            'job_title' => $staff->job_title,
            'employment_status' => $staff->employment_status->value,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $data */
    private function assignmentScopeChanges(array $before, array $data): bool
    {
        return (array_key_exists('branch_id', $data) && $data['branch_id'] !== $before['branch_id'])
            || (array_key_exists('role', $data) && (string) $data['role'] !== $before['role'])
            || (array_key_exists('job_title', $data) && $data['job_title'] !== $before['job_title']);
    }

    /** @param array<string, mixed> $before */
    private function synchronizeStaffHistoryAfterMutation(User $staff, array $before, ?int $actorId): void
    {
        $current = $this->currentStaffAssignment($staff);
        $after = $this->staffState($staff);
        $assignmentChanged = $before['branch_id'] !== $after['branch_id']
            || $before['role'] !== $after['role']
            || $before['job_title'] !== $after['job_title'];
        $workArea = $before['role'] === $after['role']
            ? ($current?->work_area ?? $this->defaultWorkArea($staff->role))
            : $this->defaultWorkArea($staff->role);
        $statusChanged = $before['employment_status'] !== $after['employment_status'];
        $assignment = null;
        $now = CarbonImmutable::now();

        if ($staff->employment_status === StaffEmploymentStatus::Left) {
            $this->closeCurrentStaffAssignment($staff, $now, $current);
        } elseif ($assignmentChanged || $before['employment_status'] === StaffEmploymentStatus::Left->value) {
            $this->closeCurrentStaffAssignment($staff, $now, $current);
            $assignment = $this->createStaffAssignment($staff, $actorId, $now, $workArea, 'Cập nhật thông tin công tác');
        } else {
            $assignment = $this->ensureCurrentStaffAssignment($staff, $actorId);
        }

        if ($assignmentChanged) {
            $beforeAssignment = [
                'branch_id' => $before['branch_id'],
                'role' => $before['role'],
                'job_title' => $before['job_title'],
                'work_area' => $workArea,
            ];
            $afterAssignment = [
                'branch_id' => $after['branch_id'],
                'role' => $after['role'],
                'job_title' => $after['job_title'],
                'work_area' => $workArea,
            ];
            $this->recordAssignmentEvents($staff, $actorId, $assignment?->id, $beforeAssignment, $afterAssignment, 'Cập nhật thông tin công tác');
        }

        if ($statusChanged) {
            $from = StaffEmploymentStatus::from($before['employment_status']);
            $to = $staff->employment_status;
            $this->recordStaffEvent($staff, StaffLifecycleEvent::EMPLOYMENT_STATUS_CHANGED, $actorId, $assignment?->id, [
                'from' => $from->value,
                'to' => $to->value,
            ], "Thay đổi trạng thái từ {$from->label()} sang {$to->label()}");
        }
    }

    private function ensureCurrentStaffAssignment(User $staff, ?int $actorId, ?string $reason = null): ?StaffAssignment
    {
        if ($staff->employment_status !== StaffEmploymentStatus::Working) {
            return null;
        }

        $current = $this->currentStaffAssignment($staff);
        if ($current !== null) {
            return $current;
        }

        return $this->createStaffAssignment(
            $staff,
            $actorId,
            CarbonImmutable::now(),
            $this->defaultWorkArea($staff->role),
            $reason,
        );
    }

    private function createStaffAssignment(
        User $staff,
        ?int $actorId,
        mixed $effectiveFrom,
        ?string $workArea,
        ?string $reason,
    ): StaffAssignment {
        if ($staff->role === UserRole::Customer) {
            throw ValidationException::withMessages([
                'role' => ['Khách hàng không thể có phân công nhân viên'],
            ]);
        }
        if ($staff->employment_status !== StaffEmploymentStatus::Working) {
            throw ValidationException::withMessages([
                'status' => ['Nhân viên đã nghỉ việc không thể nhận phân công đang hoạt động'],
            ]);
        }
        if ($this->currentStaffAssignment($staff) !== null) {
            throw ValidationException::withMessages([
                'assignment' => ['Nhân viên đã có một phân công đang hoạt động'],
            ]);
        }
        $this->assertSensibleAssignmentTarget($staff->role, $staff->branch_id, $workArea);

        return $staff->staffAssignments()->create([
            'branch_id' => $staff->branch_id,
            'role' => $staff->role,
            'job_title' => $staff->job_title,
            'work_area' => $workArea,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'reason' => $reason,
            'created_by' => $actorId,
        ]);
    }

    private function currentStaffAssignment(User $staff): ?StaffAssignment
    {
        $currentAssignments = $staff->staffAssignments()
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->get();
        if ($currentAssignments->count() > 1) {
            throw ValidationException::withMessages([
                'assignment' => ['Dữ liệu phân công không hợp lệ: nhân viên có nhiều hơn một phân công đang hoạt động'],
            ]);
        }

        return $currentAssignments->first();
    }

    private function closeCurrentStaffAssignment(
        User $staff,
        CarbonImmutable $effectiveTo,
        ?StaffAssignment $current = null,
    ): void {
        $current ??= $this->currentStaffAssignment($staff);
        if ($current === null) {
            return;
        }

        $staff->staffAssignments()
            ->whereKey($current->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => $effectiveTo, 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $metadata */
    private function recordStaffEvent(
        User $staff,
        string $eventType,
        ?int $actorId,
        ?int $assignmentId,
        array $metadata,
        ?string $description,
    ): StaffLifecycleEvent {
        return $staff->staffLifecycleEvents()->create([
            'assignment_id' => $assignmentId,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function recordAssignmentEvents(
        User $staff,
        ?int $actorId,
        ?int $assignmentId,
        array $before,
        array $after,
        ?string $reason,
    ): void {
        $metadata = ['before' => $before, 'after' => $after, 'reason' => $reason];
        $this->recordStaffEvent($staff, StaffLifecycleEvent::ASSIGNMENT_CHANGED, $actorId, $assignmentId, $metadata, 'Thay đổi phân công công tác');

        foreach ([
            'branch_id' => [StaffLifecycleEvent::BRANCH_TRANSFERRED, 'Chuyển chi nhánh công tác'],
            'role' => [StaffLifecycleEvent::ROLE_CHANGED, 'Thay đổi vai trò hệ thống'],
            'job_title' => [StaffLifecycleEvent::JOB_TITLE_CHANGED, 'Thay đổi chức danh công việc'],
        ] as $field => [$eventType, $description]) {
            if ($before[$field] !== $after[$field]) {
                $this->recordStaffEvent($staff, $eventType, $actorId, $assignmentId, [
                    'from' => $before[$field],
                    'to' => $after[$field],
                    'reason' => $reason,
                ], $description);
            }
        }
    }

    /** @return array{can_transfer: bool, blockers: array<int, array{type: string, count: int, message: string, action: string}>} */
    private function staffBlockers(User $staff): array
    {
        $appointments = Appointment::query()
            ->where('technician_id', $staff->id)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::InProgress->value,
            ])
            ->count();
        $blockers = [];
        if ($appointments > 0) {
            $blockers[] = [
                'type' => 'appointments',
                'count' => $appointments,
                'message' => "Nhân viên còn {$appointments} lịch hẹn đang hoạt động hoặc chưa hoàn tất",
                'action' => 'reassign_appointments',
            ];
        }

        $openPosSessions = PosSession::query()
            ->where('cashier_id', $staff->id)
            ->where('status', 'open')
            ->where('expires_at', '>', now())
            ->count();
        if ($openPosSessions > 0) {
            $blockers[] = [
                'type' => 'pos_sessions',
                'count' => $openPosSessions,
                'message' => "Nhân viên còn {$openPosSessions} phiên POS đang mở và chưa hết hạn",
                'action' => 'complete_pos_sessions',
            ];
        }

        return ['can_transfer' => $blockers === [], 'blockers' => $blockers];
    }

    private function assertNoStaffBlockers(User $staff): void
    {
        $preflight = $this->staffBlockers($staff);
        if (! $preflight['can_transfer']) {
            throw ValidationException::withMessages([
                'assignment' => [$preflight['blockers'][0]['message']],
            ]);
        }
    }

    /** @param array<string, mixed> $target */
    private function assertCanChangeAssignment(User $actor, User $staff, array $target): void
    {
        $this->assertCanManageStaff($actor, $staff);
        $role = isset($target['role']) ? UserRole::from((string) $target['role']) : $staff->role;
        $branchId = array_key_exists('branch_id', $target) ? $target['branch_id'] : $staff->branch_id;
        $workArea = array_key_exists('work_area', $target)
            ? $target['work_area']
            : ($role === $staff->role
                ? $staff->staffAssignments()->whereNull('effective_to')->value('work_area')
                : $this->defaultWorkArea($role));
        $this->assertSensibleAssignmentTarget($role, $branchId, $workArea);

        if ($actor->role === UserRole::BranchManager
            && ($branchId !== $actor->branch_id || ! in_array($role, [UserRole::Cashier, UserRole::SalesStaff, UserRole::Technician], true))) {
            throw ValidationException::withMessages([
                'branch_id' => ['Quản lý chi nhánh chỉ có thể phân công nhân viên thông thường trong chi nhánh của mình'],
            ]);
        }
    }

    private function assertCanManageStaff(User $actor, User $staff): void
    {
        if ($actor->role === UserRole::SuperAdmin) {
            return;
        }

        if ($actor->role === UserRole::BranchManager
            && $staff->branch_id === $actor->branch_id
            && in_array($staff->role, [UserRole::Cashier, UserRole::SalesStaff, UserRole::Technician], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'staff' => ['Bạn không có quyền thực hiện thao tác này với nhân viên'],
        ]);
    }

    private function assertSensibleAssignmentTarget(UserRole $role, mixed $branchId, mixed $workArea): void
    {
        if ($role === UserRole::SuperAdmin) {
            if ($branchId !== null || $workArea !== 'system') {
                throw ValidationException::withMessages([
                    'assignment' => ['Super Admin phải thuộc khu vực system và không thuộc chi nhánh'],
                ]);
            }

            return;
        }

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Nhân viên phải được gán vào một chi nhánh']]);
        }
        $expectedWorkArea = $this->defaultWorkArea($role);
        if ($workArea !== $expectedWorkArea) {
            throw ValidationException::withMessages([
                'work_area' => ["Vai trò {$role->label()} phải thuộc khu vực {$expectedWorkArea}"],
            ]);
        }
    }

    private function assertPreservesUsableSuperAdmin(
        User $staff,
        UserRole $targetRole,
        StaffEmploymentStatus $targetStatus,
        bool $deleting,
    ): void {
        $currentlyUsable = $staff->role === UserRole::SuperAdmin
            && $staff->employment_status === StaffEmploymentStatus::Working
            && ! $staff->trashed();
        $willBeUsable = ! $deleting
            && $targetRole === UserRole::SuperAdmin
            && $targetStatus === StaffEmploymentStatus::Working;
        if (! $currentlyUsable || $willBeUsable) {
            return;
        }

        $otherUsableAdmins = User::query()
            ->whereKeyNot($staff->id)
            ->where('role', UserRole::SuperAdmin->value)
            ->where('employment_status', StaffEmploymentStatus::Working->value)
            ->lockForUpdate()
            ->count();
        if ($otherUsableAdmins === 0) {
            throw ValidationException::withMessages([
                'staff' => ['Không thể vô hiệu hóa Super Admin cuối cùng. Vui lòng tạo một Super Admin khác trước'],
            ]);
        }
    }

    private function defaultWorkArea(UserRole $role): ?string
    {
        return match ($role) {
            UserRole::Technician => 'clinic',
            UserRole::Cashier, UserRole::SalesStaff => 'retail',
            UserRole::BranchManager => 'management',
            UserRole::SuperAdmin => 'system',
            default => null,
        };
    }
}
