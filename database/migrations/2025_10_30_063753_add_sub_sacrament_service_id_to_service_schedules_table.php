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
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('SubSacramentServiceID')->nullable()->after('ServiceID');
            
            $table->foreign('SubSacramentServiceID')
                  ->references('SubSacramentServiceID')
                  ->on('sub_sacrament_services')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->dropForeign(['SubSacramentServiceID']);
            $table->dropColumn('SubSacramentServiceID');
        });
    }
};
