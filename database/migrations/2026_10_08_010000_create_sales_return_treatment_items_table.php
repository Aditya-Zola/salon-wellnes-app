<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_treatment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('treatment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('treatment_name');
            // Harga bersih pada invoice disalin agar audit retur tidak ikut berubah
            // jika harga master treatment kemudian diperbarui.
            $table->unsignedBigInteger('original_amount');
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->index(['transaction_item_id', 'sales_return_id'], 'return_treatment_items_transaction_index');
            $table->index(['treatment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_treatment_items');
    }
};
