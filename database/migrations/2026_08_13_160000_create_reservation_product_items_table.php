<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_product_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name');
            $table->string('unit_code', 30)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->unsignedBigInteger('unit_price');
            $table->timestamps();

            $table->unique(['reservation_id', 'product_id'], 'reservation_product_item_unique');
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_product_items');
    }
};
