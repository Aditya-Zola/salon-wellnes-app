<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('group')->default('general');
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name')->nullable();
            $table->boolean('is_system')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['group', 'label', 'sort_order']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'is_system']);
        });
    }
};
