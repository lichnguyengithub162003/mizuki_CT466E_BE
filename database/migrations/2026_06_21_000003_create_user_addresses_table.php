<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_phone', 20);
            $table->string('province');
            $table->string('district');
            $table->string('ward');
            $table->string('hamlet')->nullable();
            $table->string('address_line');
            $table->boolean('is_default')->default(false);
            $table->string('province_code', 20)->nullable();
            $table->unsignedInteger('ghn_province_id')->nullable();
            $table->unsignedInteger('ghn_district_id')->nullable();
            $table->string('ghn_ward_code', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
