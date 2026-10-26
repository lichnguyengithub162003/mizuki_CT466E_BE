<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('status', 20)->default('published')->index();
            $table->boolean('is_featured')->default(false);
        });

        DB::table('categories')->where('is_active', false)->update(['status' => 'hidden']);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'is_featured']);
        });
    }
};
