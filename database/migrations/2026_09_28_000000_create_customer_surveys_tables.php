<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_surveys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_number', 50);
            $table->string('customer_name', 150);
            $table->string('facility_rating', 30);
            $table->string('reception_rating', 30);
            $table->string('return_intent', 30);
            $table->string('price_rating', 30);
            $table->text('feedback')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->index(['submitted_at']);
            $table->index(['return_intent', 'submitted_at']);
        });

        Schema::create('customer_survey_therapist_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_name', 150);
            $table->string('rating', 30);
            $table->timestamps();
            $table->unique(['customer_survey_id', 'employee_id'], 'survey_therapist_employee_unique');
            $table->index(['rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_survey_therapist_ratings');
        Schema::dropIfExists('customer_surveys');
    }
};
