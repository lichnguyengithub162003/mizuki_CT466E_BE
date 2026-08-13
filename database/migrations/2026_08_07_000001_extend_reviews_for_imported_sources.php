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
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('source', 32)->nullable()->index()->after('id');
            $table->string('source_key', 64)->nullable()->after('source');
            $table->string('source_author_name')->nullable()->after('source_key');
            $table->boolean('source_verified_purchase')->nullable()->after('source_author_name');
            $table->string('source_date')->nullable()->after('source_verified_purchase');
            $table->string('variant_purchased')->nullable()->after('source_date');
            $table->json('images')->nullable()->after('variant_purchased');
            $table->text('mizuki_response_content')->nullable()->after('images');
            $table->unique(['source', 'source_key'], 'reviews_source_key_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('reviews')->whereNull('user_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore reviews.user_id to non-nullable while imported reviews exist.',
            );
        }

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique('reviews_source_key_unique');
            $table->dropIndex(['source']);
            $table->dropColumn([
                'source',
                'source_key',
                'source_author_name',
                'source_verified_purchase',
                'source_date',
                'variant_purchased',
                'images',
                'mizuki_response_content',
            ]);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
