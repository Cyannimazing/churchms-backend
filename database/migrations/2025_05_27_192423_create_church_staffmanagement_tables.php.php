<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
       
        // ChurchRole Table
        Schema::create('ChurchRole', function (Blueprint $table) {
            $table->id('RoleID');
            $table->unsignedBigInteger('ChurchID');
            $table->string('RoleName', 100);
            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->unique(['ChurchID', 'RoleName'], 'uq_church_role_churchid_rolename');
        });

        // Permission Table
        Schema::create('Permission', function (Blueprint $table) {
            $table->id('PermissionID');
            $table->string('PermissionName', 100)->unique();
        });

        // RolePermission Table (Pivot)
        Schema::create('RolePermission', function (Blueprint $table) {
            $table->unsignedBigInteger('RoleID');
            $table->unsignedBigInteger('PermissionID');
            $table->primary(['RoleID', 'PermissionID']);
            $table->foreign('RoleID')->references('RoleID')->on('ChurchRole')->onDelete('cascade');
            $table->foreign('PermissionID')->references('PermissionID')->on('Permission')->onDelete('cascade');
        });

        // UserChurchRole Table
        Schema::create('UserChurchRole', function (Blueprint $table) {
            $table->id('UserChurchRoleID');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('ChurchID');
            $table->unsignedBigInteger('RoleID');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->foreign('RoleID')->references('RoleID')->on('ChurchRole')->onDelete('cascade');
            $table->unique(['user_id', 'ChurchID'], 'uq_user_church_role');
        });
    }

    public function down()
    {
        Schema::dropIfExists('UserChurchRole');
        Schema::dropIfExists('RolePermission');
        Schema::dropIfExists('Permission');
        Schema::dropIfExists('ChurchRole');
    }
};
