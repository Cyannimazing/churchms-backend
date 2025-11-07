<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('Church', function (Blueprint $table) {
            $table->string('City')->nullable()->after('Longitude');
            $table->string('Province')->nullable()->after('City');
        });
    }

    public function down(): void
    {
        Schema::table('Church', function (Blueprint $table) {
            $table->dropColumn(['City', 'Province']);
        });
    }
};
