<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserContact;
use App\Models\ChurchSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role_id' => ['required', 'in:1,2'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:1'],
            'last_name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string'],
            'contact_number' => ['required', 'string', 'max:20'],
        ]);

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'first_name' => $request->first_name,
            'middle_name' => $request->middle_name,
            'last_name' => $request->last_name,
            'system_role_id' => $request->role_id,
        ]);

        UserContact::create([
            'user_id' => $user->id,
            'address' => $request->address,
            'contact_number' => $request->contact_number,
        ]);

        // Auto-assign Free Plan for Church Owners
        if ($request->role_id == 2) {
            // Only use the Free Plan that already exists (from seeder). Do NOT create one here.
            $freePlan = SubscriptionPlan::where('PlanName', 'Free')->first();

            if ($freePlan) {
                // Avoid accidental duplicates: if user somehow already has any subscription, skip
                $existingSub = ChurchSubscription::where('UserID', $user->id)
                    ->whereIn('Status', ['Active', 'Pending'])
                    ->first();

                if (!$existingSub) {
                    $startDate = now();
                    // For testing: 3 minutes trial
                    $endDate = $startDate->copy()->addMinutes(3);

                    // Create church subscription with Free Plan from seeder
                    ChurchSubscription::create([
                        'UserID' => $user->id,
                        'PlanID' => $freePlan->PlanID,
                        'StartDate' => $startDate,
                        'EndDate' => $endDate,
                        'Status' => 'Active',
                    ]);

                    // Create transaction record for the free plan
                    SubscriptionTransaction::create([
                        'user_id' => $user->id,
                        'NewPlanID' => $freePlan->PlanID,
                        'PaymentMethod' => 'Free Trial',
                        'AmountPaid' => 0.00,
                        'TransactionDate' => now(),
                        'Notes' => 'Free trial subscription automatically assigned during ChurchOwner registration (3min test)',
                    ]);
                }
            }
        }

        event(new Registered($user));

        // Create token for the newly registered user
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load(['profile.systemRole', 'contact'])
        ], 201);
    }
}