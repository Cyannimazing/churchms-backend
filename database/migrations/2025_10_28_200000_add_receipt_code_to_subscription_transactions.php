<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('SubscriptionTransaction', function (Blueprint $table) {
            $table->string('receipt_code', 20)->nullable()->after('SubTransactionID')->unique();
            $table->string('paymongo_session_id')->nullable()->after('receipt_code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('SubscriptionTransaction', function (Blueprint $table) {
            $table->dropColumn(['receipt_code', 'paymongo_session_id']);
        });
    }
};
