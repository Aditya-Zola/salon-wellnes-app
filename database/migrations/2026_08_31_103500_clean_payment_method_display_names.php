<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_methods')
            ->whereIn('type', ['card', 'bank_transfer', 'qris'])
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function (object $method): void {
                $name = trim((string) $method->name);
                $cleanName = trim((string) preg_replace(
                    '/\s*\|\s*(?:BANK|TRANSFER|BANK_TRANSFER|QRIS|EDC|KARTU|CARD)\s*$/i',
                    '',
                    $name,
                ));

                if ($cleanName !== '' && $cleanName !== $name) {
                    DB::table('payment_methods')->where('id', $method->id)->update([
                        'name' => $cleanName,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Normalisasi label lama tidak dikembalikan agar nama input pengguna tetap bersih.
    }
};
