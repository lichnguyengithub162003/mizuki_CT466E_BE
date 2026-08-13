<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_question_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_question_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->nullable();
            $table->string('external_key', 64)->nullable();
            $table->string('author_name')->nullable();
            $table->text('answer');
            $table->dateTime('answered_at')->nullable();
            $table->string('source_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_question_id', 'source', 'external_key'], 'product_question_answers_source_unique');
            $table->index(['product_question_id', 'sort_order'], 'product_question_answers_order_index');
            $table->index(['source', 'external_key'], 'product_question_answers_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_question_answers');
    }
};
