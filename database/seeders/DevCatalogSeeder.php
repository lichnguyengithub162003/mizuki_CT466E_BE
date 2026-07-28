<?php

namespace Database\Seeders;

use App\Enums\BranchType;
use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\BranchInventory;
use App\Models\BranchService;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DevCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $skinCare = Category::query()->updateOrCreate(['slug' => 'cham-soc-da'], [
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
            $categories->push(Category::query()->updateOrCreate(['slug' => $category['slug']], $category + [
                'parent_id' => $skinCare->id,
                'is_active' => true,
            ]));
        }

        $categories->push(Category::query()->updateOrCreate(['slug' => 'trang-diem'], [
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
            $brands->push(Brand::query()->updateOrCreate(['slug' => $slug], [
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
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, Brand>  $brands
     */
    private function seedProducts(Collection $categories, Collection $brands): void
    {
        $branch = Branch::query()->updateOrCreate(['code' => 'MZ-NK-01'], [
            'code' => 'MZ-NK-01',
            'name' => 'Mizuki Ninh Kiều',
            'branch_type' => BranchType::Store,
            'phone' => '02923888888',
            'email' => 'dev-cantho@mizuki.test',
            'address' => 'Đường 3/2, Ninh Kiều, Cần Thơ',
            'province_code' => 'CT',
            'ghn_district_id' => 1442,
            'ghn_ward_code' => '21012',
            'is_active' => true,
        ]);

        $clinic = Branch::query()->updateOrCreate(['code' => 'MZ-SKIN-NK-01'], [
            'name' => 'Mizuki Clinic Ninh Kiều',
            'branch_type' => BranchType::Hybrid,
            'phone' => '02923889999',
            'email' => 'dev-clinic-cantho@mizuki.test',
            'address' => 'Nguyen Van Cu, Ninh Kieu, Can Tho',
            'province_code' => 'CT',
            'ghn_district_id' => 1442,
            'ghn_ward_code' => '21012',
            'is_active' => true,
        ]);

        $this->seedClinicCatalog($clinic);
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
            $product = Product::query()->updateOrCreate(['slug' => $slug], [
                'category_id' => $categories[$index % $categories->count()]->id,
                'brand_id' => $brands[$index % $brands->count()]->id,
                'name' => $name,
                'slug' => $slug,
                'short_description' => "Sản phẩm {$name} chính hãng tại Mizuki.",
                'origin_country' => 'Nhật Bản',
                'is_active' => true,
                'is_featured' => $index < 5,
            ]);

            ProductImage::query()->updateOrCreate([
                'product_id' => $product->id,
                'is_primary' => true,
            ], [
                'product_id' => $product->id,
                'image_url' => "https://placehold.co/600x600?text={$slug}",
                'alt_text' => $name,
                'sort_order' => 0,
                'is_primary' => true,
            ]);

            $variantCount = ($index % 3) + 1;

            for ($variantIndex = 0; $variantIndex < $variantCount; $variantIndex++) {
                $price = 100_000 + ($index * 25_000) + ($variantIndex * 30_000);
                $sku = 'DEV-'.strtoupper(Str::slug($slug, '')).'-'.($variantIndex + 1);
                $variant = ProductVariant::query()->updateOrCreate(['sku' => $sku], [
                    'product_id' => $product->id,
                    'name' => ($variantIndex + 1) * 50 .' ml',
                    'sku' => $sku,
                    'attributes' => ['capacity' => (($variantIndex + 1) * 50).' ml'],
                    'price' => $price,
                    'sale_price' => ($index + $variantIndex) % 4 === 0 ? $price - 10_000 : null,
                    'weight' => ($variantIndex + 1) * 50,
                    'sort_order' => $variantIndex,
                    'is_active' => true,
                ]);

                if ($index < 5 && $variantIndex === 0) {
                    BranchInventory::query()->updateOrCreate([
                        'branch_id' => $branch->id,
                        'product_variant_id' => $variant->id,
                    ], [
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

    private function seedClinicCatalog(Branch $clinic): void
    {
        foreach (range(0, 6) as $weekday) {
            $isClosed = $weekday === 0;

            BranchBusinessHour::query()->updateOrCreate([
                'branch_id' => $clinic->id,
                'weekday' => $weekday,
            ], [
                'opens_at' => $isClosed ? null : '09:00:00',
                'closes_at' => $isClosed ? null : '20:00:00',
                'is_closed' => $isClosed,
            ]);
        }

        $services = [
            [
                'category' => 'skin_care',
                'name' => 'Chăm sóc da chuyên sâu',
                'slug' => 'mizuki-deep-skin-care',
                'short_description' => 'Làm sạch sâu và cấp ẩm cho da.',
                'description' => 'Liệu trình chăm sóc da gồm làm sạch, cấp ẩm và thư giãn.',
                'image_url' => 'https://placehold.co/600x400?text=skin-care',
                'duration_minutes' => 60,
                'price' => 450_000,
                'is_active' => true,
                'sort_order' => 1,
                'capacity' => 2,
            ],
            [
                'category' => 'acne_care',
                'name' => 'Chăm sóc da mụn',
                'slug' => 'mizuki-acne-extraction',
                'short_description' => 'Làm sạch và chăm sóc da dễ nổi mụn.',
                'description' => 'Liệu trình làm sạch da, chăm sóc vùng da dễ nổi mụn và hướng dẫn chăm sóc sau liệu trình.',
                'image_url' => 'https://placehold.co/600x400?text=acne-care',
                'duration_minutes' => 90,
                'price' => 650_000,
                'is_active' => true,
                'sort_order' => 2,
                'capacity' => 1,
            ],
        ];

        foreach ($services as $attributes) {
            $capacity = $attributes['capacity'];
            unset($attributes['capacity']);
            $service = Service::query()->updateOrCreate(['slug' => $attributes['slug']], $attributes);

            BranchService::query()->updateOrCreate([
                'branch_id' => $clinic->id,
                'service_id' => $service->id,
            ], [
                'is_available' => true,
                'capacity' => $capacity,
            ]);
        }
    }
}
