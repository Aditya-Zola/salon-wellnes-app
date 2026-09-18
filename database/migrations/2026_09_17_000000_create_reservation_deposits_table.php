<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('reference_number', 100)->nullable();
            $table->dateTime('paid_at');
            $table->string('status', 30)->default('confirmed');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'paid_at']);
            $table->index(['payment_method_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_deposits');
    }
};
