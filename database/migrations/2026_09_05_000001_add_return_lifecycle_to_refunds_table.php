<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->boolean('return_required')->default(false)->after('settlement_reference');
            $table->string('return_status', 30)->default('not_required')->after('return_required');
            $table->timestamp('return_received_at')->nullable()->after('return_status');
            $table->timestamp('restocked_at')->nullable()->after('return_received_at');

            $table->index(['return_required', 'return_status'], 'refunds_return_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex('refunds_return_state_index');
            $table->dropColumn([
                'return_required',
                'return_status',
                'return_received_at',
                'restocked_at',
            ]);
        });
    }
};
