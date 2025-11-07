<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCertificateVerificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('certificate_verifications', function (Blueprint $table) {
            $table->id('VerificationID');
            $table->unsignedBigInteger('AppointmentID');
            $table->unsignedBigInteger('ChurchID');
            $table->string('CertificateType', 50); // marriage, baptism, confirmation, firstCommunion
            $table->string('VerificationToken', 100)->unique();
            $table->json('CertificateData'); // Store the certificate data used
            $table->string('RecipientName', 255);
            $table->date('CertificateDate');
            $table->string('IssuedBy', 255);
            $table->boolean('IsActive')->default(true);
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('AppointmentID')->references('AppointmentID')->on('Appointment')->onDelete('cascade');
            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            
            // Indexes
            $table->index(['VerificationToken']);
            $table->index(['ChurchID', 'CertificateType']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('certificate_verifications');
    }
}
