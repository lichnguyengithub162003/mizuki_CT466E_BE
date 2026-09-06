<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('origin', 30)->nullable()->after('settlement_reference');
            $table->string('settlement_status', 30)->nullable()->after('origin');
            $table->string('return_inspection_status', 30)->nullable()->after('return_status');

            $table->index('origin');
            $table->index('settlement_status');
            $table->index('return_inspection_status');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex(['origin']);
            $table->dropIndex(['settlement_status']);
            $table->dropIndex(['return_inspection_status']);
            $table->dropColumn(['origin', 'settlement_status', 'return_inspection_status']);
        });
    }
};
