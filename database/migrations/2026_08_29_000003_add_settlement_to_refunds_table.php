<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('settlement_method', 30)->nullable()->after('wallet_transaction_id');
            $table->string('settlement_reference', 120)->nullable()->after('settlement_method');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn(['settlement_method', 'settlement_reference']);
        });
    }
};
