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
            $table->enum('member_discount_type', ['percentage', 'fixed'])->nullable()->after('advanceBookingUnit');
            $table->decimal('member_discount_value', 10, 2)->nullable()->after('member_discount_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacrament_service', function (Blueprint $table) {
            $table->dropColumn(['member_discount_type', 'member_discount_value']);
        });
    }
};
