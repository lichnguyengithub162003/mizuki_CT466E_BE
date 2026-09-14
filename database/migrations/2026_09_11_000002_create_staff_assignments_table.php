<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 30);
            $table->string('job_title')->nullable();
            $table->string('work_area', 30)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'effective_to']);
            $table->index(['branch_id', 'effective_from']);
        });

        DB::table('users')
            ->where('role', '!=', 'customer')
            ->orderBy('id')
            ->chunkById(100, function ($staff): void {
                foreach ($staff as $member) {
                    if (DB::table('staff_assignments')->where('staff_id', $member->id)->whereNull('effective_to')->exists()) {
                        continue;
                    }

                    DB::table('staff_assignments')->insert([
                        'staff_id' => $member->id,
                        'branch_id' => $member->branch_id,
                        'role' => $member->role,
                        'job_title' => $member->job_title,
                        'work_area' => match ($member->role) {
                            'technician' => 'clinic',
                            'cashier', 'sales_staff' => 'retail',
                            'branch_manager' => 'management',
                            'super_admin' => 'system',
                            default => null,
                        },
                        'effective_from' => $member->created_at ?? now(),
                        'effective_to' => null,
                        'reason' => 'Khởi tạo từ dữ liệu nhân viên hiện có',
                        'created_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_assignments');
    }
};
