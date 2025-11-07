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
            $table->boolean('isDownloadableContent')->default(false)->after('isStaffForm');
            $table->integer('advanceBookingNumber')->default(3)->after('isDownloadableContent');
            $table->enum('advanceBookingUnit', ['weeks', 'months'])->default('weeks')->after('advanceBookingNumber');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacrament_service', function (Blueprint $table) {
            $table->dropColumn(['isDownloadableContent', 'advanceBookingNumber', 'advanceBookingUnit']);
        });
    }
};
