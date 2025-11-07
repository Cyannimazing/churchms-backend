<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('Clergy', function (Blueprint $table) {
            $table->id('ClergyID');
            $table->unsignedBigInteger('ChurchID');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 1)->nullable();
            $table->string('position', 100); // e.g., "Parish Priest", "Deacon", "Bishop"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->index(['ChurchID', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('Clergy');
    }
};
