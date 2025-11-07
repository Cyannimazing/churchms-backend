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
            // Rename the column from isDownloadableContent to isMass
            $table->renameColumn('isDownloadableContent', 'isMass');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacrament_service', function (Blueprint $table) {
            // Rename the column back to isDownloadableContent
            $table->renameColumn('isMass', 'isDownloadableContent');
        });
    }
};
