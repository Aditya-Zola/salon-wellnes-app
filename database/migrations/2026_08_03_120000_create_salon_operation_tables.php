<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('therapists', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('specialty')->nullable(); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('treatments', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('category'); $table->unsignedInteger('duration_minutes'); $table->unsignedBigInteger('price'); $table->decimal('commission_percent', 5, 2)->default(0); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('category'); $table->decimal('stock', 12, 2)->default(0); $table->string('unit', 20); $table->decimal('minimum_stock', 12, 2)->default(0); $table->unsignedBigInteger('selling_price')->default(0); $table->timestamps();
        });
        Schema::create('treatment_product', function (Blueprint $table) {
            $table->id(); $table->foreignId('treatment_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained()->cascadeOnDelete(); $table->decimal('quantity', 12, 2); $table->unique(['treatment_id','product_id']);
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('phone')->unique(); $table->boolean('is_member')->default(false); $table->date('member_since')->nullable(); $table->unsignedInteger('visit_count')->default(0); $table->timestamps();
        });
        Schema::create('promotions', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->decimal('discount_percent', 5, 2); $table->date('starts_at'); $table->date('ends_at'); $table->boolean('members_only')->default(true); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('reservations', function (Blueprint $table) {
            $table->id(); $table->string('queue_number'); $table->foreignId('customer_id')->constrained(); $table->foreignId('treatment_id')->constrained(); $table->foreignId('therapist_id')->constrained(); $table->date('reservation_date'); $table->time('reservation_time'); $table->string('status')->default('Terjadwal'); $table->text('notes')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps(); $table->unique(['reservation_date','queue_number']);
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->id(); $table->string('number')->unique(); $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); $table->unsignedBigInteger('subtotal'); $table->decimal('discount_percent', 5, 2)->default(0); $table->unsignedBigInteger('discount_amount')->default(0); $table->unsignedBigInteger('total'); $table->string('payment_method'); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('transaction_id')->constrained()->cascadeOnDelete(); $table->string('item_type'); $table->unsignedBigInteger('item_id')->nullable(); $table->string('name'); $table->decimal('quantity', 12, 2)->default(1); $table->unsignedBigInteger('price'); $table->unsignedBigInteger('total');
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id(); $table->foreignId('product_id')->constrained(); $table->string('type'); $table->decimal('quantity', 12, 2); $table->string('source'); $table->string('reference')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id(); $table->string('type'); $table->string('category'); $table->text('description'); $table->unsignedBigInteger('amount'); $table->date('entry_date'); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id(); $table->string('employee_name'); $table->string('position'); $table->string('period', 7); $table->unsignedBigInteger('base_salary'); $table->unsignedBigInteger('bonus')->default(0); $table->unsignedBigInteger('late_deduction')->default(0); $table->unsignedBigInteger('commission')->default(0); $table->string('late_duration')->nullable(); $table->timestamps(); $table->unique(['employee_name','period']);
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('action'); $table->string('subject_type'); $table->unsignedBigInteger('subject_id')->nullable(); $table->text('description'); $table->json('metadata')->nullable(); $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['activity_logs','payrolls','cash_entries','stock_movements','transaction_items','transactions','reservations','promotions','customers','treatment_product','products','treatments','therapists'] as $table) Schema::dropIfExists($table);
    }
};
