<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            // Source fields following the salon's manual remuneration workbook.
            // They stay editable per employee and period; totals are calculated
            // by the application, never typed as a final salary.
            $table->decimal('paid_work_days', 6, 2)->default(0);
            $table->unsignedBigInteger('daily_rate')->default(0);
            $table->decimal('overtime_days', 6, 2)->default(0);
            $table->unsignedBigInteger('meal_allowance')->default(0);
            $table->unsignedBigInteger('target_bonus')->default(0);
            $table->unsignedBigInteger('service_bonus')->default(0);
            $table->unsignedBigInteger('attendance_bonus')->default(0);
            $table->unsignedBigInteger('attendance_allowance')->default(0);
            $table->unsignedBigInteger('other_allowance')->default(0);
            $table->unsignedBigInteger('tip_deposit')->default(0);
            $table->decimal('absence_days', 6, 2)->default(0);
            $table->unsignedBigInteger('absence_deduction')->default(0);
            $table->unsignedBigInteger('late_rate_per_minute')->default(0);
            $table->unsignedBigInteger('cash_advance')->default(0);
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'paid_work_days',
                'daily_rate',
                'overtime_days',
                'meal_allowance',
                'target_bonus',
                'service_bonus',
                'attendance_bonus',
                'attendance_allowance',
                'other_allowance',
                'tip_deposit',
                'absence_days',
                'absence_deduction',
                'late_rate_per_minute',
                'cash_advance',
                'notes',
            ]);
        });
    }
};
