<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->string('value', 255);
            $table->timestamps();
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('account_name', 150)->nullable()->after('name');
            $table->string('account_number', 100)->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn(['account_name', 'account_number']);
        });

        Schema::dropIfExists('sale_settings');
    }
};
