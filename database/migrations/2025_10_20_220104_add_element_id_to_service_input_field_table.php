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
        Schema::table('service_input_field', function (Blueprint $table) {
            $table->string('element_id')->nullable()->after('InputFieldID')->comment('String identifier for tracing form elements in documents and reports');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_input_field', function (Blueprint $table) {
            $table->dropColumn('element_id');
        });
    }
};
