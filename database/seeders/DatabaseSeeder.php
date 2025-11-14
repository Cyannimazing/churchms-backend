<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserContact;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\ChurchSubscription;
use App\Models\Church;
use App\Models\ChurchRole;
use App\Models\UserChurchRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed provinces and cities
        $this->call(ProvincesCitiesSeeder::class);

        // Insert system roles
        DB::table('system_roles')->insertOrIgnore([
            ['role_name' => 'Regular'],
            ['role_name' => 'ChurchOwner'],
            ['role_name' => 'ChurchStaff'],
            ['role_name' => 'Admin'],
        ]);

        // Subscription plans
        SubscriptionPlan::firstOrCreate([
            'PlanName' => 'Free',
        ], [
            'Price' => 0.00,
            'DurationInMonths' => 1,
            'MaxChurchesAllowed' => 1,
            'Description' => 'Free trial plan for new church owners',
        ]);

        SubscriptionPlan::firstOrCreate([
            'PlanName' => 'Basic',
        ], [
            'Price' => 29.99,
            // 12-month subscription for Basic plan
            'DurationInMonths' => 12,
            'MaxChurchesAllowed' => 2,
            'Description' => 'Basic annual plan for church owners',
        ]);

        SubscriptionPlan::firstOrCreate([
            'PlanName' => 'Premium',
        ], [
            'Price' => 49.99,
            // 12-month subscription for Premium plan
            'DurationInMonths' => 12,
            'MaxChurchesAllowed' => 3,
            'Description' => 'Premium annual plan for church owners',
        ]);

        // Get role IDs for system_roles table
        $roles = DB::table('system_roles')->pluck('id', 'role_name');

        // Create Admin user
        $admin = User::firstOrCreate(['email' => 'admin@example.com'], [
            'password' => Hash::make('123123123'),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ]);
        UserProfile::firstOrCreate(['user_id' => $admin->id], [
            'first_name' => 'Admin',
            'last_name' => 'User',
            'system_role_id' => $roles['Admin'] ?? null,
        ]);

        // Create Regular user
        $regular = User::firstOrCreate(['email' => 'regular@example.com'], [
            'password' => Hash::make('123123123'),
            'email_verified_at' => now(),
        ]);
        UserProfile::firstOrCreate(['user_id' => $regular->id], [
            'first_name' => 'Regular',
            'last_name' => 'User',
            'system_role_id' => $roles['Regular'] ?? null,
        ]);

        // Create ChurchOwner user with subscription
        $owner = User::firstOrCreate(['email' => 'owner@example.com'], [
            'password' => Hash::make('123123123'),
            'email_verified_at' => now(),
        ]);
        UserProfile::firstOrCreate(['user_id' => $owner->id], [
            'first_name' => 'Owner',
            'last_name' => 'User',
            'system_role_id' => $roles['ChurchOwner'] ?? null,
        ]);
        $plan = SubscriptionPlan::first();
        ChurchSubscription::firstOrCreate([
            'UserID' => $owner->id,
            'PlanID' => $plan->PlanID,
        ], [
            'StartDate' => now(),
            'EndDate' => now()->addMinutes(3), // 3 minutes for testing
            'Status' => 'Active',
        ]);
        // Seed permissions
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
    }
}
