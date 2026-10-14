<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing accounts are backfilled to generation 1 by the column default.
        // Pre-migration staff sessions have no bound generation and fail closed.
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('auth_generation')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('auth_generation');
        });
    }
};
