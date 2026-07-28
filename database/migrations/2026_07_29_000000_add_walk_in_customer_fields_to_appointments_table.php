<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->string('customer_name', 100)->nullable()->after('user_id');
            $table->string('customer_phone', 20)->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        if (DB::table('appointments')->whereNull('user_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back walk-in appointment support while appointments with a null user_id exist.',
            );
        }

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['customer_name', 'customer_phone']);
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }
};
