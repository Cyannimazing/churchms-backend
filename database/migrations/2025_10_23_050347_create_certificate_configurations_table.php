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
        Schema::create('certificate_configurations', function (Blueprint $table) {
            $table->id('CertificateConfigID');
            $table->unsignedBigInteger('ChurchID');
            $table->string('CertificateType'); // baptism, matrimony, confirmation, firstCommunion
            $table->unsignedBigInteger('ServiceID')->nullable()->comment('Linked sacrament service');
            $table->json('FieldMappings')->nullable()->comment('JSON mapping of certificate fields to element_ids');
            $table->boolean('IsEnabled')->default(true);
            $table->timestamps();

            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('set null');
            $table->unique(['ChurchID', 'CertificateType']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificate_configurations');
    }
};
