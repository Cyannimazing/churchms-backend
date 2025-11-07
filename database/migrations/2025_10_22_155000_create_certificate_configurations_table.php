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
            $table->string('CertificateType')->default('marriage'); // marriage, baptism, confirmation, etc.
            $table->unsignedBigInteger('SacramentServiceID')->nullable()->comment('Linked sacrament service for field binding');
            $table->json('field_mappings')->nullable()->comment('JSON mapping of certificate fields to element IDs');
            $table->json('form_data')->nullable()->comment('Default form data values');
            $table->timestamps();

            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->foreign('SacramentServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('set null');
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
