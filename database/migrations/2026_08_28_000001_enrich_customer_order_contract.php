<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('product_slug')->nullable()->after('product_id');
            $table->foreignId('brand_id')
                ->nullable()
                ->after('product_slug')
                ->constrained()
                ->nullOnDelete();
            $table->string('brand_name')->nullable()->after('brand_id');
            $table->string('brand_slug')->nullable()->after('brand_name');
            $table->unsignedBigInteger('original_unit_price')->nullable()->after('variant_attributes');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('cancellation_requested_by', 30)
                ->nullable()
                ->after('cancellation_reason');
            $table->foreignId('cancellation_requested_by_user_id')
                ->nullable()
                ->after('cancellation_requested_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('cancellation_requested_at')
                ->nullable()
                ->after('cancellation_requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancellation_requested_by_user_id');
            $table->dropColumn([
                'cancellation_requested_by',
                'cancellation_requested_at',
            ]);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn([
                'product_slug',
                'brand_name',
                'brand_slug',
                'original_unit_price',
            ]);
        });
    }
};
