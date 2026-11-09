<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_deposit_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('reference_number')->nullable();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_deposit_refunds');
    }
};
