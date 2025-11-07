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
        Schema::create('sub_sacrament_services', function (Blueprint $table) {
            $table->id('SubSacramentServiceID');
            $table->unsignedBigInteger('ParentServiceID');
            $table->string('SubServiceName', 100);
            $table->decimal('fee', 10, 2)->default(0);
            $table->timestamps();

            // Foreign key to parent service
            $table->foreign('ParentServiceID')
                  ->references('ServiceID')
                  ->on('sacrament_service')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_sacrament_services');
    }
};
