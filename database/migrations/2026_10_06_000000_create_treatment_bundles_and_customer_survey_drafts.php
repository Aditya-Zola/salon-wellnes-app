<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_bundles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->unsignedBigInteger('bundle_price');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('treatment_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('treatment_bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['treatment_bundle_id', 'treatment_id']);
        });

        Schema::table('customer_surveys', function (Blueprint $table): void {
            $table->string('status', 20)->default('submitted')->after('customer_name');
            $table->string('customer_phone', 30)->nullable()->after('customer_name');
            $table->string('facility_rating', 30)->nullable()->change();
            $table->string('reception_rating', 30)->nullable()->change();
            $table->string('return_intent', 30)->nullable()->change();
            $table->string('price_rating', 30)->nullable()->change();
            $table->timestamp('submitted_at')->nullable()->change();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_surveys', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'customer_phone']);
        });
        Schema::dropIfExists('treatment_bundle_items');
        Schema::dropIfExists('treatment_bundles');
    }
};
