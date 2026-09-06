<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('upload_token')->unique();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('staging_key')->unique();
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('original_name')->nullable();
            $table->string('status', 30)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('promoted_at')->nullable();
            $table->string('final_key')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'expires_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_uploads');
    }
};
