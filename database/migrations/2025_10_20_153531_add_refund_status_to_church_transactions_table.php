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
            $table->enum('refund_status', ['none', 'refunded'])->default('none')->after('transaction_type');
            $table->datetime('refund_date')->nullable()->after('refund_status');
            $table->text('refund_reason')->nullable()->after('refund_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_transactions', function (Blueprint $table) {
            $table->dropColumn(['refund_status', 'refund_date', 'refund_reason']);
        });
    }
};
