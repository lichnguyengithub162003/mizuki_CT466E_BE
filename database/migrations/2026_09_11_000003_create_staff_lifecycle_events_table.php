<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('staff_assignments')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50)->index();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['staff_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_lifecycle_events');
    }
};
