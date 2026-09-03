<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapist_ratings', function (Blueprint $table): void {
            $table->text('review')->nullable()->after('stars');
        });
    }

    public function down(): void
    {
        Schema::table('therapist_ratings', function (Blueprint $table): void {
            $table->dropColumn('review');
        });
    }
};
