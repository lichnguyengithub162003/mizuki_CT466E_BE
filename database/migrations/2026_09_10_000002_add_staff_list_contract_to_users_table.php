<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('staff_code', 30)->nullable()->unique()->after('id');
            $table->string('job_title')->nullable()->after('staff_code');
            $table->string('employment_status', 20)->nullable()->index()->after('role');
        });

        DB::table('users')
            ->where('role', '!=', 'customer')
            ->orderBy('id')
            ->chunkById(100, function ($staff): void {
                foreach ($staff as $member) {
                    DB::table('users')->where('id', $member->id)->update([
                        'staff_code' => 'NV-'.str_pad((string) $member->id, 5, '0', STR_PAD_LEFT),
                        'employment_status' => 'working',
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['staff_code']);
            $table->dropIndex(['employment_status']);
            $table->dropColumn(['staff_code', 'job_title', 'employment_status']);
        });
    }
};
