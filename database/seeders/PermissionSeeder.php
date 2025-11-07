<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Appointment
            'appointment_list',
            'appointment_review',
            'appointment_saveFormData',
            'appointment_acceptApplication',
            'appointment_rejectApplication',
            'appointment_markCompleted',
            'appointment_generateCertificate',
            'appointment_generateMassReport',
            
            // Transaction Record
            'transaction_list',
            'transaction_setupFee',
            'transaction_editFee',
            'transaction_view',
            'transaction_refund',
            
            // Roles
            'role_list',
            'role_add',
            'role_edit',
            'role_delete',
            
            // Employee
            'employee_list',
            'employee_add',
            'employee_edit',
            'employee_deactivate',
            
            // Service
            'service_list',
            'service_add',
            'service_edit',
            'service_delete',
            'service_configure',
            
            // Schedule
            'schedule_list',
            'schedule_add',
            'schedule_edit',
            'schedule_delete',
            
            // Signature
            'signature_list',
            'signature_add',
            'signature_delete',
            
            // Member Application
            'member-application_list',
            'member-application_review',
            
            // Member Directory
            'member-directory_list',
            'member-directory_review',
            'member-directory_edit',
            'member-directory_markAsAway',
            'member-directory_exportPDF',
            
            // Certificate Config
            'certificate-config_list',
            'certificate-config_fieldMapping',
        ];
        
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['PermissionName' => $perm]);
        }
        
        $this->command->info('Permissions seeded successfully!');
    }
}
