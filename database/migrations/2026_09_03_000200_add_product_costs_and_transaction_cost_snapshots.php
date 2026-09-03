<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // HPP untuk satu satuan pakai. Contoh: bila stok dihitung dalam ml,
            // maka nominal ini adalah HPP untuk setiap ml, bukan per botol beli.
            $table->unsignedBigInteger('cost_price')->default(0)->after('selling_price');
        });

        Schema::table('transaction_items', function (Blueprint $table): void {
            // Nilai ini disalin ketika transaksi dibayar agar laporan lama tidak
            // ikut berubah saat HPP pada master produk diperbarui.
            $table->unsignedBigInteger('unit_cost')->default(0)->after('unit_price');
            $table->unsignedBigInteger('cost_amount')->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost', 'cost_amount']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('cost_price');
        });
    }
};
