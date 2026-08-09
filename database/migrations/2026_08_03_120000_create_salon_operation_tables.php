<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function createTableIfMissing(string $tableName, \Closure $definition): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, $definition);
        }
    }

    public function up(): void
    {
        $this->createTableIfMissing('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('position', 100)->nullable();
            $table->string('specialty')->nullable();
            $table->boolean('is_service_provider')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'is_service_provider']);
        });

        $this->createTableIfMissing('treatment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createTableIfMissing('treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('treatment_categories');
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedBigInteger('normal_price');
            $table->decimal('default_commission_percent', 7, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });

        $this->createTableIfMissing('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('decimal_places')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createTableIfMissing('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('category', 100)->nullable();
            $table->foreignId('purchase_unit_id')->constrained('units');
            $table->foreignId('usage_unit_id')->constrained('units');
            $table->decimal('purchase_to_usage_factor', 18, 4)->default(1);
            $table->decimal('current_stock', 18, 4)->default(0);
            $table->decimal('minimum_stock', 18, 4)->default(0);
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });

        $this->createTableIfMissing('treatment_product_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('quantity', 18, 4);
            $table->timestamps();

            $table->unique(['treatment_id', 'product_id'], 'treatment_product_recipe_unique');
        });

        $this->createTableIfMissing('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('phone', 30)->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_member')->default(false);
            $table->date('member_since')->nullable();
            $table->unsignedInteger('visit_count')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'is_member']);
        });

        $this->createTableIfMissing('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('discount_type', 20)->default('percent');
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->date('starts_at');
            $table->date('ends_at');
            $table->boolean('members_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        $this->createTableIfMissing('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 40)->unique();
            $table->string('queue_number', 20);
            $table->foreignId('customer_id')->constrained();
            $table->date('reservation_date');
            $table->time('reservation_time');
            $table->string('source', 30)->default('walk_in');
            $table->string('status', 30)->default('scheduled');
            $table->text('general_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['reservation_date', 'queue_number']);
            $table->index(['reservation_date', 'status']);
            $table->index(['customer_id', 'reservation_date']);
        });

        $this->createTableIfMissing('reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained();
            $table->string('treatment_name');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedBigInteger('normal_price');
            $table->unsignedBigInteger('unit_price');
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('net_price');
            $table->decimal('commission_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('continued_at')->nullable();
            $table->dateTime('overtime_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('work_status', 30)->default('waiting');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['scheduled_start_at', 'scheduled_end_at'], 'reservation_items_schedule_index');
            $table->index(['reservation_id', 'sort_order']);
            $table->index('work_status');
        });

        $this->createTableIfMissing('reservation_item_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->string('role', 30)->default('primary');
            $table->decimal('commission_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->text('conflict_override_reason')->nullable();
            $table->foreignId('conflict_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('conflict_overridden_at')->nullable();
            $table->timestamps();

            $table->unique(['reservation_item_id', 'employee_id'], 'reservation_item_staff_unique');
            $table->index(['employee_id', 'role']);
        });

        $this->createTableIfMissing('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->string('type', 30);
            $table->boolean('is_cash')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->createTableIfMissing('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('reservation_id')->unique()->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('status', 30)->default('draft');
            $table->dateTime('transacted_at')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'transacted_at']);
            $table->index(['customer_id', 'transacted_at']);
        });

        $this->createTableIfMissing('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type', 30);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('name');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('gross_amount');
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['item_type', 'item_id']);
            $table->index(['transaction_id', 'sort_order']);
        });

        $this->createTableIfMissing('transaction_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('reference_number', 100)->nullable();
            $table->dateTime('paid_at');
            $table->string('status', 30)->default('confirmed');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transaction_id', 'status']);
            $table->index(['payment_method_id', 'paid_at']);
        });

        $this->createTableIfMissing('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('unit_id')->constrained('units');
            $table->string('type', 30);
            $table->decimal('quantity', 18, 4);
            $table->decimal('stock_before', 18, 4);
            $table->decimal('stock_after', 18, 4);
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['product_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        $this->createTableIfMissing('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('category', 100);
            $table->text('description');
            $table->unsignedBigInteger('amount');
            $table->date('entry_date');
            $table->string('status', 30)->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['entry_date', 'type']);
            $table->index(['status', 'entry_date']);
        });

        $this->createTableIfMissing('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->string('period', 7);
            $table->string('employee_name');
            $table->string('position')->nullable();
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('bonus')->default(0);
            $table->unsignedBigInteger('overtime')->default(0);
            $table->unsignedBigInteger('commission')->default(0);
            $table->unsignedBigInteger('late_deduction')->default(0);
            $table->unsignedBigInteger('other_deduction')->default(0);
            $table->unsignedBigInteger('net_salary')->default(0);
            $table->unsignedInteger('late_duration_minutes')->default(0);
            $table->string('status', 30)->default('draft');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'period']);
            $table->index(['period', 'status']);
        });

        $this->createTableIfMissing('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('transaction_payments');
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('reservation_item_staff');
        Schema::dropIfExists('reservation_items');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('treatment_product_recipes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('treatments');
        Schema::dropIfExists('treatment_categories');
        Schema::dropIfExists('employees');
    }
};
