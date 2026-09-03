<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapist_ratings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('stars')->default(3)->after('rating');
        });

        // Riwayat versi awal tetap dibawa ke skala bintang. Nilai tengah
        // digunakan untuk "Bagus", sedangkan kategori ekstrem menjadi 1/5.
        DB::table('therapist_ratings')->where('rating', 'poor')->update(['stars' => 1]);
        DB::table('therapist_ratings')->where('rating', 'good')->update(['stars' => 3]);
        DB::table('therapist_ratings')->where('rating', 'professional')->update(['stars' => 5]);
    }

    public function down(): void
    {
        Schema::table('therapist_ratings', function (Blueprint $table): void {
            $table->dropColumn('stars');
        });
    }
};
