<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_entries', function (Blueprint $table): void {
            // operating masuk laba-rugi; tiga kelompok lain hanya memengaruhi
            // posisi neraca agar modal/prive/pembelian persediaan tidak terbaca
            // sebagai laba atau biaya operasional.
            $table->string('report_group', 30)->default('operating')->after('type');
            $table->index(['report_group', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_entries', function (Blueprint $table): void {
            $table->dropIndex(['report_group', 'entry_date']);
            $table->dropColumn('report_group');
        });
    }
};
