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
        Schema::table('church_transactions', function (Blueprint $table) {
            $table->string('receipt_code', 32)->nullable()->unique()->after('appointment_id');
            $table->index('receipt_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_transactions', function (Blueprint $table) {
            $table->dropIndex(['receipt_code']);
            $table->dropColumn('receipt_code');
        });
    }
};
