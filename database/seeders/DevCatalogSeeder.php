<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DevCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $skinCare = Category::query()->create([
            'name' => 'Chăm sóc da',
            'slug' => 'cham-soc-da',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $categories = collect([$skinCare]);

        foreach ([
            ['name' => 'Sữa rửa mặt', 'slug' => 'sua-rua-mat', 'sort_order' => 1],
            ['name' => 'Serum', 'slug' => 'serum', 'sort_order' => 2],
            ['name' => 'Kem dưỡng', 'slug' => 'kem-duong', 'sort_order' => 3],
        ] as $category) {
            $categories->push(Category::query()->create($category + [
                'parent_id' => $skinCare->id,
                'is_active' => true,
            ]));
        }

        $categories->push(Category::query()->create([
            'name' => 'Trang điểm',
            'slug' => 'trang-diem',
            'sort_order' => 2,
            'is_active' => true,
        ]));

        $brands = collect();

        foreach ([
            ['Anessa', 'anessa'],
            ['La Roche-Posay', 'la-roche-posay'],
            ['L’Oréal Paris', 'loreal-paris'],
            ['Maybelline', 'maybelline'],
            ['Vichy', 'vichy'],
        ] as [$name, $slug]) {
            $brands->push(Brand::query()->create([
                'name' => $name,
                'slug' => $slug,
                'logo_url' => "https://placehold.co/300x150?text={$slug}",
                'banner_image' => "https://placehold.co/1200x400?text={$slug}",
                'description' => "Gian hàng chính hãng {$name} tại Mizuki.",
                'is_active' => true,
            ]));
        }

        $this->seedProducts($categories, $brands);
    }

    /**
     * @param Collection<int, Category> $categories
     * @param Collection<int, Brand> $brands
     */
    private function seedProducts(Collection $categories, Collection $brands): void
    {
        $branch = Branch::query()->first() ?? Branch::query()->create([
            'code' => 'DEV-CT',
            'name' => 'Mizuki Cần Thơ Dev',
            'phone' => '02923888888',
            'email' => 'dev-cantho@mizuki.test',
            'address' => 'Đường 3/2, Ninh Kiều, Cần Thơ',
            'province_code' => 'CT',
            'ghn_district_id' => 1442,
            'ghn_ward_code' => '21012',
            'is_active' => true,
        ]);

        $productNames = [
            'Sữa Rửa Mặt Dịu Nhẹ',
            'Gel Rửa Mặt Cho Da Dầu',
            'Serum Phục Hồi Da',
            'Serum Vitamin C Sáng Da',
            'Kem Dưỡng Cấp Ẩm',
            'Kem Dưỡng Phục Hồi',
            'Kem Chống Nắng Nâng Tông',
            'Kem Chống Nắng Kiểm Soát Dầu',
            'Nước Tẩy Trang Dịu Nhẹ',
            'Toner Cân Bằng Da',
            'Mặt Nạ Dưỡng Ẩm',
            'Son Lì Mịn Môi',
            'Phấn Nước Che Phủ',
            'Mascara Dài Mi',
            'Chì Kẻ Mày Tự Nhiên',
        ];

        foreach ($productNames as $index => $name) {
            $slug = Str::slug($name).'-'.($index + 1);
            $product = Product::query()->create([
                'category_id' => $categories[$index % $categories->count()]->id,
                'brand_id' => $brands[$index % $brands->count()]->id,
                'name' => $name,
                'slug' => $slug,
                'short_description' => "Sản phẩm {$name} chính hãng tại Mizuki.",
                'origin_country' => 'Nhật Bản',
                'is_active' => true,
                'is_featured' => $index < 5,
            ]);

            ProductImage::query()->create([
                'product_id' => $product->id,
                'image_url' => "https://placehold.co/600x600?text={$slug}",
                'alt_text' => $name,
                'sort_order' => 0,
                'is_primary' => true,
            ]);

            $variantCount = ($index % 3) + 1;

            for ($variantIndex = 0; $variantIndex < $variantCount; $variantIndex++) {
                $price = 100_000 + ($index * 25_000) + ($variantIndex * 30_000);
                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'name' => ($variantIndex + 1) * 50 . ' ml',
                    'sku' => 'DEV-'.strtoupper(Str::slug($slug, '')).'-'.($variantIndex + 1),
                    'attributes' => ['capacity' => (($variantIndex + 1) * 50).' ml'],
                    'price' => $price,
                    'sale_price' => ($index + $variantIndex) % 4 === 0 ? $price - 10_000 : null,
                    'weight' => ($variantIndex + 1) * 50,
                    'sort_order' => $variantIndex,
                    'is_active' => true,
                ]);

                if ($index < 5 && $variantIndex === 0) {
                    BranchInventory::query()->create([
                        'branch_id' => $branch->id,
                        'product_variant_id' => $variant->id,
                        'quantity' => 20 + $index,
                        'reserved_quantity' => 0,
                        'reorder_level' => 5,
                    ]);
                }
            }
        }
    }
}
