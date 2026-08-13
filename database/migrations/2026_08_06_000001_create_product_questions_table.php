<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->nullable();
            $table->string('external_key', 64)->nullable();
            $table->string('author_name')->nullable();
            $table->text('question');
            $table->dateTime('asked_at')->nullable();
            $table->string('source_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'source', 'external_key']);
            $table->index(['product_id', 'sort_order']);
            $table->index(['source', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_questions');
    }
};
