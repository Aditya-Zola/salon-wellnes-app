<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_items', function (Blueprint $table): void {
            $table->foreignId('treatment_bundle_id')->nullable()->after('treatment_id')->constrained()->nullOnDelete();
            $table->string('treatment_bundle_name', 150)->nullable()->after('treatment_bundle_id');
            $table->string('treatment_bundle_instance', 64)->nullable()->after('treatment_bundle_name');
            $table->index(['reservation_id', 'treatment_bundle_id'], 'reservation_items_bundle_index');
        });

        Schema::table('transaction_items', function (Blueprint $table): void {
            $table->foreignId('treatment_bundle_id')->nullable()->after('item_id')->constrained()->nullOnDelete();
            $table->string('treatment_bundle_name', 150)->nullable()->after('treatment_bundle_id');
            $table->string('treatment_bundle_instance', 64)->nullable()->after('treatment_bundle_name');
            $table->index(['transaction_id', 'treatment_bundle_id'], 'transaction_items_bundle_index');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table): void {
            $table->dropIndex('transaction_items_bundle_index');
            $table->dropConstrainedForeignId('treatment_bundle_id');
            $table->dropColumn('treatment_bundle_name');
            $table->dropColumn('treatment_bundle_instance');
        });

        Schema::table('reservation_items', function (Blueprint $table): void {
            $table->dropIndex('reservation_items_bundle_index');
            $table->dropConstrainedForeignId('treatment_bundle_id');
            $table->dropColumn('treatment_bundle_name');
            $table->dropColumn('treatment_bundle_instance');
        });
    }
};
