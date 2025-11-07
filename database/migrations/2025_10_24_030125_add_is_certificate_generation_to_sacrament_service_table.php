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
            $table->boolean('isCertificateGeneration')->default(false)->after('member_discount_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacrament_service', function (Blueprint $table) {
            $table->dropColumn('isCertificateGeneration');
        });
    }
};
