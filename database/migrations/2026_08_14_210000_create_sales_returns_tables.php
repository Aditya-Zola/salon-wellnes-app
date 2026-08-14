<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('refunded_amount')->default(0)->after('change_amount');
        });

        Schema::create('sales_return_sequences', function (Blueprint $table): void {
            $table->date('sequence_date')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('refund_payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->unsignedBigInteger('total_amount');
            $table->string('reference_number', 100)->nullable();
            $table->text('reason');
            $table->string('status', 30)->default('posted');
            $table->string('idempotency_key', 100)->unique();
            $table->dateTime('returned_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transaction_id', 'status']);
            $table->index(['refund_payment_method_id', 'returned_at'], 'sales_returns_method_date_index');
        });

        Schema::create('sales_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 18, 4);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('amount');
            $table->boolean('restocked')->default(true);
            $table->timestamps();

            $table->index(['transaction_item_id', 'sales_return_id'], 'sales_return_items_transaction_index');
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sales_return_sequences');

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('refunded_amount');
        });
    }
};
