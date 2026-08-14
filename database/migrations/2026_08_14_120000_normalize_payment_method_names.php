<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropUnique('payment_methods_name_unique');
        });

        $this->normalizeLegacyMethod('DEBIT', 'EDC-001', 'card');
        $this->normalizeLegacyMethod('TRANSFER_BCA', 'BANK-001', 'bank_transfer');
        $this->normalizeLegacyMethod('QRIS_BCA', 'QRIS-001', 'qris');
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->unique('name');
        });
    }

    private function normalizeLegacyMethod(string $legacyCode, string $standardCode, string $type): void
    {
        $method = DB::table('payment_methods')->where('code', $legacyCode)->first();

        if (! $method) {
            return;
        }

        $name = preg_replace('/^(?:EDC|Transfer|QRIS|Kartu)\s+/i', '', (string) $method->name) ?: $method->name;
        $standardCodeIsAvailable = ! DB::table('payment_methods')
            ->where('code', $standardCode)
            ->where('id', '!=', $method->id)
            ->exists();

        DB::table('payment_methods')->where('id', $method->id)->update([
            'code' => $standardCodeIsAvailable ? $standardCode : $legacyCode,
            'name' => $name,
            'type' => $type,
            'updated_at' => now(),
        ]);
    }
};
