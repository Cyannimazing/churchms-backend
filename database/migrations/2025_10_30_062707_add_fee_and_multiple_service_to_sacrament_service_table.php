<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sacrament_service', function (Blueprint $table) {
            $table->decimal('fee', 10, 2)->default(0)->after('member_discount_value');
            $table->boolean('isMultipleService')->default(false)->after('fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacrament_service', function (Blueprint $table) {
            $table->dropColumn(['fee', 'isMultipleService']);
        });
    }
};
