<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('discount_type', 20);
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('max_discount_amount')->nullable();
            $table->unsignedBigInteger('minimum_order_amount')->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->string('applies_to', 30)->default('order');
            $table->json('scope')->nullable();
            $table->json('rules')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->foreign('promotion_id')
                ->references('id')
                ->on('promotions')
                ->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreign('promotion_id')
                ->references('id')
                ->on('promotions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['promotion_id']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign(['promotion_id']);
        });

        Schema::dropIfExists('promotions');
    }
};
