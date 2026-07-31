<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_recovery_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index();
            $table->string('otp_hash');
            $table->timestamp('otp_expires_at');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('resend_available_at');
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamp('otp_consumed_at')->nullable();
            $table->string('verification_token_hash', 64)->nullable()->unique();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('token_consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'email', 'otp_consumed_at'], 'password_recovery_active_otp_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_recovery_challenges');
    }
};
