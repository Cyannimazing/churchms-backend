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
            $table->enum('cancellation_category', ['no_fee', 'with_fee'])->nullable()->after('Status');
            $table->text('cancellation_note')->nullable()->after('cancellation_category');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Appointment', function (Blueprint $table) {
            $table->dropColumn(['cancellation_category', 'cancellation_note', 'cancelled_at']);
        });
    }
};
