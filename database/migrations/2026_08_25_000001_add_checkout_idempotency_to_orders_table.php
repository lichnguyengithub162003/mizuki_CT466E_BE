<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->char('checkout_idempotency_key_hash', 64)
                ->nullable()
                ->after('order_number');
            $table->char('checkout_request_hash', 64)
                ->nullable()
                ->after('checkout_idempotency_key_hash');
            $table->unique(
                ['user_id', 'checkout_idempotency_key_hash'],
                'orders_user_checkout_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_user_checkout_idempotency_unique');
            $table->dropColumn([
                'checkout_idempotency_key_hash',
                'checkout_request_hash',
            ]);
        });
    }
};
