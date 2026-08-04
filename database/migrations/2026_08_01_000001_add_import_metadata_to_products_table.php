<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->after('id');
            $table->string('external_id', 100)->nullable()->after('source');
            $table->string('source_url', 2048)->nullable()->after('external_id');
            $table->json('specifications')->nullable()->after('usage_instructions');

            $table->unique(['source', 'external_id'], 'products_source_external_unique');
            $table->index(['source', 'is_active'], 'products_source_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_source_external_unique');
            $table->dropIndex('products_source_active_index');
            $table->dropColumn([
                'source',
                'external_id',
                'source_url',
                'specifications',
            ]);
        });
    }
};
