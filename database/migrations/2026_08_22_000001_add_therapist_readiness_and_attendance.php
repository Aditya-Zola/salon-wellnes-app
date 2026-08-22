<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_items', function (Blueprint $table) {
            $table->dateTime('scheduled_ready_at')->nullable()->after('scheduled_end_at');
            $table->index('scheduled_ready_at', 'reservation_items_ready_index');
        });

        // Existing bookings stored only the treatment duration. Bring them to
        // the same model as new bookings: 15 minutes preparation + 45 minutes rest.
        DB::table('reservation_items')->orderBy('id')->each(function (object $item): void {
            if ($item->scheduled_end_at === null) {
                return;
            }

            $end = CarbonImmutable::parse($item->scheduled_end_at)->addMinutes(15);
            DB::table('reservation_items')->where('id', $item->id)->update([
                'scheduled_end_at' => $end,
                'scheduled_ready_at' => $end->addMinutes(45),
            ]);
        }, 200);

        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status', 20); // present | off
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendances');

        Schema::table('reservation_items', function (Blueprint $table) {
            $table->dropIndex('reservation_items_ready_index');
            $table->dropColumn('scheduled_ready_at');
        });
    }
};
