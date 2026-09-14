<?php

use App\Enums\BranchStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('status', 20)
                ->default(BranchStatus::Active->value)
                ->index()
                ->after('is_active');
        });

        DB::table('branches')
            ->where('is_active', true)
            ->update(['status' => BranchStatus::Active->value]);
        DB::table('branches')
            ->where('is_active', false)
            ->update(['status' => BranchStatus::Inactive->value]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
