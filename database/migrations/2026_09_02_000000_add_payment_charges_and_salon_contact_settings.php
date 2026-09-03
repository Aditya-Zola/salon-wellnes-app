<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_methods') && ! Schema::hasColumn('payment_methods', 'charge_percent')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->decimal('charge_percent', 7, 4)->default(0);
                $table->boolean('charge_default_enabled')->default(true);
            });
        }

        if (Schema::hasTable('transactions') && ! Schema::hasColumn('transactions', 'payment_charge_amount')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->unsignedBigInteger('payment_charge_amount')->default(0);
            });
        }

        if (Schema::hasTable('transaction_payments') && ! Schema::hasColumn('transaction_payments', 'base_amount')) {
            Schema::table('transaction_payments', function (Blueprint $table): void {
                $table->unsignedBigInteger('base_amount')->default(0);
                $table->decimal('charge_percent', 7, 4)->default(0);
                $table->unsignedBigInteger('charge_amount')->default(0);
                $table->boolean('charge_enabled')->default(false);
            });
        }

        if (Schema::hasTable('sale_settings')) {
            $now = now();
            DB::table('sale_settings')->insertOrIgnore([
                [
                    'key' => 'salon_address',
                    'value' => 'Jl. Telaga Asmara, Tlogosari Kulon, Semarang',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'key' => 'salon_whatsapp',
                    'value' => '081128702019',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transaction_payments') && Schema::hasColumn('transaction_payments', 'base_amount')) {
            Schema::table('transaction_payments', function (Blueprint $table): void {
                $table->dropColumn(['base_amount', 'charge_percent', 'charge_amount', 'charge_enabled']);
            });
        }

        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'payment_charge_amount')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->dropColumn('payment_charge_amount');
            });
        }

        if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'charge_percent')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->dropColumn(['charge_percent', 'charge_default_enabled']);
            });
        }
    }
};
