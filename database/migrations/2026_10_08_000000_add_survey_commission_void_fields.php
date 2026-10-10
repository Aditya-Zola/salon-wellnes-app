<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_item_staff', function (Blueprint $table): void {
            $table->timestamp('commission_voided_at')->nullable()->after('commission_amount');
            $table->unsignedBigInteger('commission_voided_by')->nullable()->after('commission_voided_at');
            $table->unsignedBigInteger('commission_void_survey_id')->nullable()->after('commission_voided_by');
            $table->text('commission_void_reason')->nullable()->after('commission_void_survey_id');
            $table->index('commission_voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_item_staff', function (Blueprint $table): void {
            $table->dropIndex(['commission_voided_at']);
            $table->dropColumn(['commission_voided_at', 'commission_voided_by', 'commission_void_survey_id', 'commission_void_reason']);
        });
    }
};
