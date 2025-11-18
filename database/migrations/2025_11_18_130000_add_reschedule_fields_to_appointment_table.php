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
        Schema::table('Appointment', function (Blueprint $table) {
            if (!Schema::hasColumn('Appointment', 'reschedule_count')) {
                $table->unsignedInteger('reschedule_count')->default(0)->after('cancelled_at');
            }
            if (!Schema::hasColumn('Appointment', 'last_rescheduled_at')) {
                $table->timestamp('last_rescheduled_at')->nullable()->after('reschedule_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Appointment', function (Blueprint $table) {
            if (Schema::hasColumn('Appointment', 'reschedule_count')) {
                $table->dropColumn('reschedule_count');
            }
            if (Schema::hasColumn('Appointment', 'last_rescheduled_at')) {
                $table->dropColumn('last_rescheduled_at');
            }
        });
    }
};
