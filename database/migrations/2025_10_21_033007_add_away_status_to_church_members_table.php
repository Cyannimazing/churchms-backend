<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('church_members', function (Blueprint $table) {
            // SQLite doesn't support changing enum values directly,
            // but we'll handle validation in the model/controller
            // The 'away' status will be handled at the application level
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No changes needed for rollback since we didn't modify the database structure
    }
};
