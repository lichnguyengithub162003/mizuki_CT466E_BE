<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->change();
            $table->foreignId('service_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('appointment_id')
                ->nullable()
                ->unique()
                ->after('order_item_id')
                ->constrained()
                ->restrictOnDelete();

            $table->index(['service_id', 'is_visible', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('reviews')->whereNull('product_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back service review support while reviews with a null product_id exist.',
            );
        }

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex(['service_id', 'is_visible', 'created_at']);
            $table->dropForeign(['appointment_id']);
            $table->dropUnique(['appointment_id']);
            $table->dropColumn('appointment_id');
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
            $table->foreignId('product_id')->nullable(false)->change();
        });
    }
};
