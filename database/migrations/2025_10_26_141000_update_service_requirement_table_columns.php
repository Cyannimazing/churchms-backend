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
        Schema::table('service_requirement', function (Blueprint $table) {
            // Drop the foreign key first
            $table->dropForeign(['ServiceID']);
            
            // Drop the old index
            $table->dropIndex(['ServiceID', 'IsMandatory']);
            
            // Rename IsMandatory to isNeeded
            $table->renameColumn('IsMandatory', 'isNeeded');
            
            // Add isSubmitted column
            $table->boolean('isSubmitted')->default(false)->after('isNeeded');
            
            // Create new index with new column name
            $table->index(['ServiceID', 'isNeeded']);
            
            // Recreate the foreign key
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_requirement', function (Blueprint $table) {
            // Drop the foreign key first
            $table->dropForeign(['ServiceID']);
            
            // Drop the current index
            $table->dropIndex(['ServiceID', 'isNeeded']);
            
            // Drop the new column
            $table->dropColumn('isSubmitted');
            
            // Rename back to IsMandatory
            $table->renameColumn('isNeeded', 'IsMandatory');
            
            // Restore the original index
            $table->index(['ServiceID', 'IsMandatory']);
            
            // Recreate the foreign key
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('cascade');
        });
    }
};
