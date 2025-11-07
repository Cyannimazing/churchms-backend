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
        // Remove isSubmitted column from service_requirement table since it was incorrectly added
        Schema::table('service_requirement', function (Blueprint $table) {
            if (Schema::hasColumn('service_requirement', 'isSubmitted')) {
                $table->dropColumn('isSubmitted');
            }
        });
        
        // Remove isSubmitted column from sub_service_requirements table since it was incorrectly added
        Schema::table('sub_service_requirements', function (Blueprint $table) {
            if (Schema::hasColumn('sub_service_requirements', 'isSubmitted')) {
                $table->dropColumn('isSubmitted');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back isSubmitted columns if rolling back
        Schema::table('service_requirement', function (Blueprint $table) {
            $table->boolean('isSubmitted')->default(false)->after('isNeeded');
        });
        
        Schema::table('sub_service_requirements', function (Blueprint $table) {
            $table->boolean('isSubmitted')->default(false)->after('isNeeded');
        });
    }
};
