<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds an index to help prevent duplicate appointments from being created
     * when payment success callbacks are triggered multiple times.
     * 
     * Note: MySQL doesn't support partial unique indexes (WHERE clause), so we rely on 
     * application-level checks in the code to prevent duplicates.
     */
    public function up(): void
    {
        Schema::table('church_transactions', function (Blueprint $table) {
            // Add composite index on paymongo_session_id and appointment_id
            // This helps query performance when checking for existing appointments
            // and provides some constraint protection
            $table->index(['paymongo_session_id', 'appointment_id'], 'idx_session_appointment');
            
            // Add index on paymongo_session_id alone for faster lookups
            $table->index('paymongo_session_id', 'idx_paymongo_session');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_session_appointment');
            $table->dropIndex('idx_paymongo_session');
        });
    }
};
