<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_commission_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('therapist_count');
            $table->unsignedTinyInteger('therapist_position');
            $table->decimal('commission_percent', 7, 4);
            $table->timestamps();

            $table->unique(
                ['treatment_id', 'therapist_count', 'therapist_position'],
                'treatment_commission_splits_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_commission_splits');
    }
};
