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
        Schema::table('ChurchProfile', function (Blueprint $table) {
            $table->string('Diocese')->nullable()->after('ProfilePicturePath');
            $table->string('ContactNumber')->nullable()->after('Diocese');
            $table->string('Email')->nullable()->after('ContactNumber');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ChurchProfile', function (Blueprint $table) {
            $table->dropColumn(['Diocese', 'ContactNumber', 'Email']);
        });
    }
};
