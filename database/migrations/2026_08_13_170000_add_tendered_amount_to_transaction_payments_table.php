<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transaction_payments') && ! Schema::hasColumn('transaction_payments', 'tendered_amount')) {
            Schema::table('transaction_payments', function (Blueprint $table): void {
                $table->unsignedBigInteger('tendered_amount')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transaction_payments') && Schema::hasColumn('transaction_payments', 'tendered_amount')) {
            Schema::table('transaction_payments', function (Blueprint $table): void {
                $table->dropColumn('tendered_amount');
            });
        }
    }
};
