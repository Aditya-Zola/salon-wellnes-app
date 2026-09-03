<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapist_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('rating', 20);
            $table->dateTime('rated_at');
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu therapist memperoleh satu penilaian untuk satu nota, walau
            // ia mengerjakan lebih dari satu treatment dalam kunjungan itu.
            $table->unique(['transaction_id', 'employee_id']);
            $table->index(['rating', 'rated_at']);
            $table->index(['employee_id', 'rated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_ratings');
    }
};
