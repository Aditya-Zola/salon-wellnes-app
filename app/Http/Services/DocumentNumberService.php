<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public static function prefix(string $key, string $default): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) DB::table('sale_settings')->where('key', $key)->value('value')) ?: $default);
    }

    public static function cancellation(): string
    {
        $date = today()->toDateString();
        DB::table('cancellation_sequences')->insertOrIgnore(['sequence_date' => $date, 'last_number' => 0]);
        $sequence = DB::table('cancellation_sequences')->where('sequence_date', $date)->lockForUpdate()->firstOrFail();
        $next = (int) $sequence->last_number + 1;
        DB::table('cancellation_sequences')->where('sequence_date', $date)->update(['last_number' => $next]);

        return sprintf('%s%s%03d', self::prefix('cancellation_prefix', 'CNL'), today()->format('Ymd'), $next);
    }
}
