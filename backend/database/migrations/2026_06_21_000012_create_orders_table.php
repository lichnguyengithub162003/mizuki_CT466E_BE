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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('user_address_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // The foreign key is added after the promotions table is created.
            $table->unsignedBigInteger('promotion_id')->nullable()->index();
            $table->string('channel', 20)->default('online');
            $table->string('fulfillment_method', 20)->default('shipping');
            $table->string('payment_method', 20);
            $table->string('status', 30)->default('pending')->index();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('province_code', 20)->nullable();
            $table->unsignedInteger('ghn_district_id')->nullable();
            $table->string('ghn_ward_code', 20)->nullable();
            $table->string('shipping_address')->nullable();
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('shipping_fee')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->text('note')->nullable();
            $table->string('cancellation_reason_type', 50)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
