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
use App\Services\Media\MediaKeyGenerator;
use App\Services\Media\MediaUrlResolver;
use Closure;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminPortalService extends BaseService
{
    public function __construct(
        private readonly AdminPortalRepository $repository,
        private readonly AdminMediaService $media,
        private readonly MediaKeyGenerator $mediaKeys,
        private readonly MediaUrlResolver $mediaUrls,
    ) {}

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
    public function createProduct(User $actor, array $data): Product
    {
        $this->validateProductVariants($data);

        return $this->saveProductWithMedia($actor, null, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(User $actor, int $id, array $data): ?Product
    {
        $product = $this->repository->product($id);
        if ($product === null) {
            return null;
        }
        $this->validateProductVariants($data, $product);

        return $this->saveProductWithMedia($actor, $product, $data);
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
    public function createCategory(User $actor, array $data): Category
    {
        return $this->saveCategoryWithMedia($actor, null, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(User $actor, int $id, array $data): ?Category
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

        return $this->saveCategoryWithMedia($actor, $category, $data);
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
    public function createBrand(User $actor, array $data): Brand
    {
        return $this->saveBrandWithMedia($actor, null, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateBrand(User $actor, int $id, array $data): ?Brand
    {
        $brand = $this->repository->brand($id);

        return $brand === null ? null : $this->saveBrandWithMedia($actor, $brand, $data);
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

        return $this->saveStaffWithMedia($actor, null, $data);
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

        return $this->saveStaffWithMedia($actor, $staff, $data);
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
    private function saveProductWithMedia(User $actor, ?Product $product, array $data): Product
    {
        $images = $data['images'] ?? null;
        $tokens = [];

        if (is_array($images)) {
            foreach ($images as $index => $image) {
                if (isset($image['upload_token'])) {
                    $tokens[] = (string) $image['upload_token'];
                } else {
                    $this->media->assertLegacyReference($image['image_url'] ?? null, "images.{$index}.image_url");
                }
            }
        }

        $this->media->assertConsumableTokens($actor, $tokens);
        $oldReferences = is_array($images) && $product !== null
            ? $product->images->pluck('image_url')->all()
            : [];

        return $this->mediaMutation(
            function (array &$promotions) use ($actor, $product, $data, $images): Product {
                $baseData = $data;
                unset($baseData['images']);
                $saved = $this->repository->saveProduct($product, $baseData);

                if (! is_array($images)) {
                    return $saved;
                }

                $resolved = [];

                foreach ($images as $index => $image) {
                    $variantId = $this->resolveProductImageVariantId($saved, $data, $image);

                    if (isset($image['upload_token'])) {
                        $token = (string) $image['upload_token'];
                        $image['image_url'] = $this->media->promoteOwned(
                            $actor,
                            $token,
                            fn ($upload): string => $variantId === null
                                ? $this->mediaKeys->productGallery($saved->id, $upload->extension)
                                : $this->mediaKeys->productVariant($saved->id, $variantId, $upload->extension),
                            $promotions,
                        );
                    } elseif (isset($image['id'])) {
                        $existingImage = $product?->images->firstWhere('id', (int) $image['id']);

                        if ($existingImage === null) {
                            throw ValidationException::withMessages([
                                "images.{$index}.id" => ['Ảnh không thuộc sản phẩm này.'],
                            ]);
                        }

                        $image['image_url'] = $existingImage->image_url;
                    }

                    unset($image['id'], $image['upload_token'], $image['variant_index']);
                    $image['product_variant_id'] = $variantId;
                    $resolved[$index] = $image;
                }

                return $this->repository->saveProduct($saved, ['images' => $resolved]);
            },
            $oldReferences,
        );
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $image
     */
    private function resolveProductImageVariantId(Product $product, array $requestData, array $image): ?int
    {
        if (isset($image['variant_index'])) {
            $variantIndex = (int) $image['variant_index'];
            $variantData = $requestData['variants'][$variantIndex] ?? null;

            if (! is_array($variantData)) {
                throw ValidationException::withMessages([
                    'images' => ['Ảnh biến thể tham chiếu biến thể không hợp lệ.'],
                ]);
            }

            if (isset($variantData['id'])) {
                return (int) $variantData['id'];
            }

            $variant = $product->variants->firstWhere('sku', $variantData['sku'] ?? null);

            if ($variant === null) {
                throw ValidationException::withMessages([
                    'images' => ['Ảnh biến thể tham chiếu biến thể không hợp lệ.'],
                ]);
            }

            return $variant->id;
        }

        return isset($image['product_variant_id']) ? (int) $image['product_variant_id'] : null;
    }

    /** @param array<string, mixed> $data */
    private function saveBrandWithMedia(User $actor, ?Brand $brand, array $data): Brand
    {
        $this->validateSingleMediaInput($actor, $data, 'logo_upload_token', 'logo_url');
        $this->validateSingleMediaInput($actor, $data, 'banner_upload_token', 'banner_image');
        $data = $this->normalizeCurrentReferences($brand, $data, [
            'logo_upload_token' => 'logo_url',
            'banner_upload_token' => 'banner_image',
        ]);
        $oldReferences = $brand === null ? [] : $this->changedOldReferences($brand, $data, [
            'logo_upload_token' => 'logo_url',
            'banner_upload_token' => 'banner_image',
        ]);

        return $this->mediaMutation(function (array &$promotions) use ($actor, $brand, $data): Brand {
            $baseData = $this->withoutTokenBackedFields($data, [
                'logo_upload_token' => 'logo_url',
                'banner_upload_token' => 'banner_image',
            ]);
            $saved = $this->repository->saveBrand($brand, $baseData);
            $updates = [];

            if (isset($data['logo_upload_token'])) {
                $updates['logo_url'] = $this->media->promoteOwned(
                    $actor,
                    (string) $data['logo_upload_token'],
                    fn ($upload): string => $this->mediaKeys->brandLogo($saved->id, $upload->extension),
                    $promotions,
                );
            }
            if (isset($data['banner_upload_token'])) {
                $updates['banner_image'] = $this->media->promoteOwned(
                    $actor,
                    (string) $data['banner_upload_token'],
                    fn ($upload): string => $this->mediaKeys->brandBanner($saved->id, $upload->extension),
                    $promotions,
                );
            }

            return $updates === [] ? $saved : $this->repository->saveBrand($saved, $updates);
        }, $oldReferences);
    }

    /** @param array<string, mixed> $data */
    private function saveCategoryWithMedia(User $actor, ?Category $category, array $data): Category
    {
        $this->validateSingleMediaInput($actor, $data, 'image_upload_token', 'image_url');
        $data = $this->normalizeCurrentReferences($category, $data, [
            'image_upload_token' => 'image_url',
        ]);
        $oldReferences = $category === null ? [] : $this->changedOldReferences($category, $data, [
            'image_upload_token' => 'image_url',
        ]);

        return $this->mediaMutation(function (array &$promotions) use ($actor, $category, $data): Category {
            $baseData = $this->withoutTokenBackedFields($data, ['image_upload_token' => 'image_url']);
            $saved = $this->repository->saveCategory($category, $baseData);

            if (! isset($data['image_upload_token'])) {
                return $saved;
            }

            $key = $this->media->promoteOwned(
                $actor,
                (string) $data['image_upload_token'],
                fn ($upload): string => $this->mediaKeys->category($saved->id, $upload->extension),
                $promotions,
            );

            return $this->repository->saveCategory($saved, ['image_url' => $key]);
        }, $oldReferences);
    }

    /** @param array<string, mixed> $data */
    private function saveCustomerWithMedia(User $actor, ?User $customer, array $data): User
    {
        $this->validateSingleMediaInput($actor, $data, 'avatar_upload_token', 'avatar');
        $data = $this->normalizeCurrentReferences($customer, $data, [
            'avatar_upload_token' => 'avatar',
        ]);
        $oldReferences = $customer === null ? [] : $this->changedOldReferences($customer, $data, [
            'avatar_upload_token' => 'avatar',
        ]);

        return $this->mediaMutation(function (array &$promotions) use ($actor, $customer, $data): User {
            $baseData = $this->withoutTokenBackedFields($data, ['avatar_upload_token' => 'avatar']);
            $saved = $this->repository->saveCustomer($actor, $customer, $baseData);

            if (! isset($data['avatar_upload_token'])) {
                return $saved;
            }

            $key = $this->media->promoteOwned(
                $actor,
                (string) $data['avatar_upload_token'],
                fn ($upload): string => $this->mediaKeys->avatar($saved->id, $upload->extension),
                $promotions,
            );

            return $this->repository->saveCustomer($actor, $saved, ['avatar' => $key]);
        }, $oldReferences);
    }

    /** @param array<string, mixed> $data */
    private function saveStaffWithMedia(User $actor, ?User $staff, array $data): User
    {
        $this->validateSingleMediaInput($actor, $data, 'avatar_upload_token', 'avatar');
        $data = $this->normalizeCurrentReferences($staff, $data, [
            'avatar_upload_token' => 'avatar',
        ]);
        $oldReferences = $staff === null ? [] : $this->changedOldReferences($staff, $data, [
            'avatar_upload_token' => 'avatar',
        ]);

        return $this->mediaMutation(function (array &$promotions) use ($actor, $staff, $data): User {
            $baseData = $this->withoutTokenBackedFields($data, ['avatar_upload_token' => 'avatar']);
            $saved = $this->repository->saveStaff($staff, $baseData);

            if (! isset($data['avatar_upload_token'])) {
                return $saved;
            }

            $key = $this->media->promoteOwned(
                $actor,
                (string) $data['avatar_upload_token'],
                fn ($upload): string => $this->mediaKeys->avatar($saved->id, $upload->extension),
                $promotions,
            );

            return $this->repository->saveStaff($saved, ['avatar' => $key]);
        }, $oldReferences);
    }

    /** @param array<string, mixed> $data */
    private function validateSingleMediaInput(User $actor, array $data, string $tokenField, string $legacyField): void
    {
        if (isset($data[$tokenField])) {
            $this->media->assertConsumableTokens($actor, [(string) $data[$tokenField]]);

            return;
        }

        if (array_key_exists($legacyField, $data)) {
            $this->media->assertLegacyReference($data[$legacyField], $legacyField);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function withoutTokenBackedFields(array $data, array $mapping): array
    {
        foreach ($mapping as $tokenField => $valueField) {
            $hasToken = isset($data[$tokenField]);
            unset($data[$tokenField]);

            if ($hasToken) {
                unset($data[$valueField]);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $mapping
     * @return array<int, string|null>
     */
    private function changedOldReferences(object $entity, array $data, array $mapping): array
    {
        $references = [];

        foreach ($mapping as $tokenField => $valueField) {
            if (array_key_exists($tokenField, $data) || array_key_exists($valueField, $data)) {
                $references[] = $entity->{$valueField};
            }
        }

        return $references;
    }

    /**
     * Preserve a canonical key when the unchanged frontend sends back the URL
     * that the API previously resolved for that key.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function normalizeCurrentReferences(?object $entity, array $data, array $mapping): array
    {
        if ($entity === null) {
            return $data;
        }

        foreach ($mapping as $tokenField => $valueField) {
            if (isset($data[$tokenField]) || ! isset($data[$valueField])) {
                continue;
            }

            $current = $entity->{$valueField};

            if (is_string($current)
                && (str_starts_with($current, 'public/') || str_starts_with($current, 'cloudinary:'))
                && $this->mediaUrls->resolvePublic($current) === $data[$valueField]) {
                $data[$valueField] = $current;
            }
        }

        return $data;
    }

    /**
     * @template T
     *
     * @param  Closure(array<int, array{upload_id: int, final_key: string}>): T  $callback
     * @param  array<int, string|null>  $oldReferences
     * @return T
     */
    private function mediaMutation(Closure $callback, array $oldReferences = []): mixed
    {
        $promotions = [];

        try {
            $result = DB::transaction(function () use ($callback, &$promotions) {
                return $callback($promotions);
            }, 1);
        } catch (Throwable $exception) {
            $this->media->compensateFailedPromotions($promotions);

            throw $exception;
        }

        $this->media->cleanupPromotedStaging($promotions);
        $this->media->cleanupReplaced($oldReferences);

        return $result;
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
