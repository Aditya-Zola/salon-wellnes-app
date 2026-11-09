<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_sequences', function (Blueprint $table): void {
            $table->date('sequence_date')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        Schema::table('reservation_deposits', fn (Blueprint $table) => $table->string('number', 60)->nullable()->unique());
        Schema::table('reservations', fn (Blueprint $table) => $table->string('cancellation_number', 60)->nullable()->unique());
        Schema::table('reservation_items', fn (Blueprint $table) => $table->string('cancellation_number', 60)->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('reservation_items', function (Blueprint $table): void {
            $table->dropUnique(['cancellation_number']);
            $table->dropColumn('cancellation_number');
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropUnique(['cancellation_number']);
            $table->dropColumn('cancellation_number');
        });
        Schema::table('reservation_deposits', function (Blueprint $table): void {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
        Schema::dropIfExists('cancellation_sequences');
    }
};
