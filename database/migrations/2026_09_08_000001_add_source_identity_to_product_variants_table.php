<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conflict = DB::table('product_variants as variants')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->whereNotNull('products.source')
            ->whereNotNull('products.external_id')
            ->selectRaw('products.source, products.external_id, COUNT(variants.id) as variant_count')
            ->groupBy('products.source', 'products.external_id')
            ->havingRaw('COUNT(variants.id) > 1')
            ->limit(1)
            ->get()
            ->isNotEmpty();

        if ($conflict) {
            throw new RuntimeException(
                'Cannot backfill variant source identity because a product source identity has multiple variants.',
            );
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->after('product_id');
            $table->string('external_id', 100)->nullable()->after('source');
            $table->string('source_url', 2048)->nullable()->after('external_id');
            $table->unique(
                ['source', 'external_id'],
                'product_variants_source_external_unique',
            );
        });

        DB::transaction(function (): void {
            DB::table('product_variants')
                ->select(['id', 'product_id'])
                ->orderBy('id')
                ->chunkById(500, function ($variants): void {
                    $products = DB::table('products')
                        ->whereIn('id', $variants->pluck('product_id')->unique()->all())
                        ->whereNotNull('source')
                        ->whereNotNull('external_id')
                        ->get(['id', 'source', 'external_id', 'source_url'])
                        ->keyBy('id');

                    foreach ($variants as $variant) {
                        $product = $products->get($variant->product_id);

                        if ($product === null) {
                            continue;
                        }

                        DB::table('product_variants')
                            ->where('id', $variant->id)
                            ->update([
                                'source' => $product->source,
                                'external_id' => $product->external_id,
                                'source_url' => $product->source_url,
                            ]);
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('product_variants_source_external_unique');
            $table->dropColumn(['source', 'external_id', 'source_url']);
        });
    }
};
