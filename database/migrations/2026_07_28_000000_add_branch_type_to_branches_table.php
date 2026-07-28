<?php

use App\Enums\BranchType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('branch_type', 20)
                ->default(BranchType::Store->value)
                ->index()
                ->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropIndex(['branch_type']);
            $table->dropColumn('branch_type');
        });
    }
};
