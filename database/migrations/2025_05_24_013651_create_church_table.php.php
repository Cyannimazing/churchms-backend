<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('Church', function (Blueprint $table) {
            $table->id('ChurchID');
            $table->string('ChurchName');
            $table->boolean('IsPublic')->default(false);
            $table->decimal('Latitude', 10, 8);
            $table->decimal('Longitude', 11, 8);
            $table->string('ChurchStatus')->default('Pending');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Church');
    }
};
