<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->date('member_expires_at')->nullable()->after('member_since');
            $table->index(['is_member', 'member_expires_at'], 'customers_member_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_member_expiry_index');
            $table->dropColumn('member_expires_at');
        });
    }
};
