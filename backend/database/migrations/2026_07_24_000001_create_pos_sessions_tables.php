<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('payment_method', 20)->default('cash');
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('order_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['cashier_id', 'branch_id', 'status']);
        });

        Schema::create('pos_session_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('variant_name');
            $table->string('sku', 100);
            $table->json('variant_attributes')->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['pos_session_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_session_items');
        Schema::dropIfExists('pos_sessions');
    }
};
