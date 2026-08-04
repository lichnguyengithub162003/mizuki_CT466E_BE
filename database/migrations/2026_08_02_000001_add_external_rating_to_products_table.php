<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('external_rating', 3, 2)->nullable()->after('is_featured');
            $table->unsignedInteger('external_review_count')->default(0)->after('external_rating');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['external_rating', 'external_review_count']);
        });
    }
};
