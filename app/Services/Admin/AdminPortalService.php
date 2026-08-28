<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Repositories\AdminPortalRepository;
use App\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminPortalService extends BaseService
{
    public function __construct(private readonly AdminPortalRepository $repository)
    {
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function dashboard(User $actor, array $filters): array
    {
        return $this->repository->dashboard($actor, $filters);
    }

    /** @param array<string, mixed> $filters */
    public function customers(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->repository->customers($actor, $filters);
    }

    public function customer(User $actor, int $id): ?User
    {
        return $this->repository->customer($actor, $id);
    }

    /** @param array<string, mixed> $filters */
    public function products(array $filters): LengthAwarePaginator
    {
        return $this->repository->products($filters);
    }

    public function product(int $id): ?Product
    {
        return $this->repository->product($id);
    }

    /** @param array<string, mixed> $data */
    public function createProduct(array $data): Product
    {
        $this->validateProductVariants($data);

        return $this->repository->saveProduct(null, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(int $id, array $data): ?Product
    {
        $product = $this->repository->product($id);
        if ($product === null) {
            return null;
        }
        $this->validateProductVariants($data, $product);

        return $this->repository->saveProduct($product, $data);
    }

    /** @param array<string, mixed> $filters */
    public function categories(array $filters): LengthAwarePaginator
    {
        return $this->repository->categories($filters);
    }

    public function category(int $id): ?Category
    {
        return $this->repository->category($id);
    }

    /** @param array<string, mixed> $data */
    public function createCategory(array $data): Category
    {
        return $this->repository->saveCategory(null, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(int $id, array $data): ?Category
    {
        $category = $this->repository->category($id);
        if ($category === null) {
            return null;
        }
        if (isset($data['parent_id'])) {
            $parent = $this->repository->category((int) $data['parent_id']);
            while ($parent !== null) {
                if ($parent->id === $category->id) {
                    throw ValidationException::withMessages(['parent_id' => ['Danh mục cha tạo thành quan hệ vòng']]);
                }
                $parent = $parent->parent_id === null ? null : $this->repository->category($parent->parent_id);
            }
        }

        return $this->repository->saveCategory($category, $data);
    }

    /** @param array<string, mixed> $filters */
    public function brands(array $filters): LengthAwarePaginator
    {
        return $this->repository->brands($filters);
    }

    public function brand(int $id): ?Brand
    {
        return $this->repository->brand($id);
    }

    /** @param array<string, mixed> $data */
    public function createBrand(array $data): Brand
    {
        return $this->repository->saveBrand(null, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateBrand(int $id, array $data): ?Brand
    {
        $brand = $this->repository->brand($id);

        return $brand === null ? null : $this->repository->saveBrand($brand, $data);
    }

    /** @param array<string, mixed> $filters */
    public function inventory(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->repository->inventory($actor, $filters);
    }

    public function inventoryTransactions(User $actor, int $id, int $perPage): ?LengthAwarePaginator
    {
        return $this->repository->inventoryTransactions($actor, $id, $perPage);
    }

    /** @param array<string, mixed> $data */
    public function adjustInventory(User $actor, int $id, array $data): mixed
    {
        return $this->repository->adjustInventory($actor, $id, $data);
    }

    /** @param array<string, mixed> $filters */
    public function branches(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->repository->branches($actor, $filters);
    }

    public function branch(User $actor, int $id): ?Branch
    {
        return $this->repository->branch($actor, $id);
    }

    /** @param array<string, mixed> $data */
    public function updateBranch(User $actor, int $id, array $data): ?Branch
    {
        $branch = $this->repository->branch($actor, $id);

        return $branch === null ? null : $this->repository->updateBranch($branch, $data);
    }

    /** @param array<string, mixed> $filters */
    public function staff(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->repository->staff($actor, $filters);
    }

    public function staffMember(User $actor, int $id): ?User
    {
        return $this->repository->staffMember($actor, $id);
    }

    /** @param array<string, mixed> $data */
    public function createStaff(User $actor, array $data): User
    {
        $this->authorizeStaffData($actor, $data);

        return $this->repository->saveStaff(null, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateStaff(User $actor, int $id, array $data): ?User
    {
        $staff = $this->repository->staffMember($actor, $id);
        if ($staff === null) {
            return null;
        }
        $effective = [
            'role' => $data['role'] ?? $staff->role->value,
            'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : $staff->branch_id,
        ];
        $this->authorizeStaffData($actor, $effective);

        return $this->repository->saveStaff($staff, $data);
    }

    /** @param array<string, mixed> $filters */
    public function reviews(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->repository->reviews($actor, $filters);
    }

    public function review(User $actor, int $id): ?Review
    {
        return $this->repository->review($actor, $id);
    }

    /** @param array<string, mixed> $data */
    public function moderateReview(User $actor, int $id, array $data): ?Review
    {
        $review = $this->repository->review($actor, $id);

        return $review === null ? null : $this->repository->moderateReview($review, $actor, $data);
    }

    /** @param array<string, mixed> $data */
    private function authorizeStaffData(User $actor, array &$data): void
    {
        $role = UserRole::from((string) $data['role']);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        if ($actor->role === UserRole::BranchManager) {
            if (! in_array($role, [UserRole::Cashier, UserRole::Technician], true)) {
                throw ValidationException::withMessages(['role' => ['Quản lý chi nhánh chỉ có thể quản lý thu ngân và kỹ thuật viên']]);
            }
            $data['branch_id'] = $actor->branch_id;
            $branchId = $actor->branch_id;
        }

        if ($role === UserRole::SuperAdmin) {
            $data['branch_id'] = null;
        } elseif ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Nhân viên phải được gán vào một chi nhánh']]);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateProductVariants(array $data, ?Product $product = null): void
    {
        if (! isset($data['variants']) || ! is_array($data['variants'])) {
            return;
        }
        $errors = [];
        foreach ($data['variants'] as $index => $variant) {
            $variantId = isset($variant['id']) ? (int) $variant['id'] : null;
            if ($variantId !== null && ($product === null || ! $product->variants->contains('id', $variantId))) {
                $errors["variants.{$index}.id"][] = 'Biến thể không thuộc sản phẩm';
            }
            $validator = Validator::make($variant, [
                'sku' => ['required', 'unique:product_variants,sku,'.($variantId ?? 'NULL')],
                'barcode' => ['nullable', 'unique:product_variants,barcode,'.($variantId ?? 'NULL')],
            ]);
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors["variants.{$index}.{$field}"] = $messages;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
