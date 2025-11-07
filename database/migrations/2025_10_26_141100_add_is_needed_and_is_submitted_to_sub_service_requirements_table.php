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
        Schema::table('sub_service_requirements', function (Blueprint $table) {
            // Add isNeeded column (defaults to true since all sub-service requirements are typically needed)
            $table->boolean('isNeeded')->default(true)->after('RequirementName');
            
            // Add isSubmitted column
            $table->boolean('isSubmitted')->default(false)->after('isNeeded');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_service_requirements', function (Blueprint $table) {
            $table->dropColumn(['isNeeded', 'isSubmitted']);
        });
    }
};
