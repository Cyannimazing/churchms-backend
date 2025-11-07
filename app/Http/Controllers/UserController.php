<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with([
            'profile.systemRole',
            'userChurchRole.church',
            'userChurchRole.role'
        ])->get()->map(function ($user) {
            if (!$user->profile) {
                return [
                    'full_name' => 'N/A',
                    'system_role_name' => 'N/A',
                    'is_active' => $user->is_active,
                    'id' => $user->id,
                    'email' => $user->email,
                    'church_membership' => null,
                ];
        }

        $middleInitial = $user->profile->middle_name ? strtoupper($user->profile->middle_name) . '.' : '';
        $fullName = sprintf(
            "%s %s %s",
            ucfirst(strtolower($user->profile->first_name)),
            $middleInitial,
            ucfirst(strtolower($user->profile->last_name))
        );

        $systemRoleName = $user->profile->systemRole->role_name ?? 'N/A';
        
        // Get church membership for Regular users
        $churchMembership = null;
        if ($systemRoleName === 'Regular') {
            $membership = \App\Models\ChurchMember::where('user_id', $user->id)
                ->where('status', 'approved')
                ->with('church')
                ->first();
            
            if ($membership && $membership->church) {
                $churchMembership = $membership->church->ChurchName;
            }
        }
        
        // Get church staff info for ChurchStaff users
        $churchStaffInfo = null;
        if ($systemRoleName === 'ChurchStaff' && $user->userChurchRole) {
            $churchStaffInfo = [
                'church_name' => $user->userChurchRole->church->ChurchName ?? 'N/A',
                'role_name' => $user->userChurchRole->role->RoleName ?? 'N/A',
            ];
        }

        return [
            'full_name' => $fullName,
            'system_role_name' => $systemRoleName,
            'is_active' => $user->is_active,
            'id' => $user->id,
            'email' => $user->email,
            'church_membership' => $churchMembership,
            'church_staff_info' => $churchStaffInfo,
        ];
    });

    return response()->json($users);
    }

    public function updateActiveStatus(Request $request, $id)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user = User::findOrFail($id);
        $user->update(['is_active' => $request->is_active]);

        return response()->json([
            'message' => 'User status updated successfully.',
            'user' => $user,
        ], 200);
    }


    public function show($id)
    {
        $user = User::with(['profile.systemRole', 'contact', 'churches', 'church', 'churchRole'])->findOrFail($id);

        // If no profile or systemRole, or not ChurchStaff, remove church and churchRole
        if (!$user->profile || !$user->profile->systemRole || $user->profile->systemRole->role_name !== 'ChurchStaff') {
            unset($user->church);
            unset($user->churchRole);
        }

        // If no profile or systemRole, or not ChurchOwner, remove churches
        if (!$user->profile || !$user->profile->systemRole || $user->profile->systemRole->role_name !== 'ChurchOwner') {
            unset($user->churches);
        }

        // Add church membership for Regular users
        if ($user->profile && $user->profile->systemRole && $user->profile->systemRole->role_name === 'Regular') {
            $membership = \App\Models\ChurchMember::where('user_id', $user->id)
                ->where('status', 'approved')
                ->with('church')
                ->first();
            
            $user->church_membership = $membership ? $membership->church : null;
        }

        return response()->json($user, 200);
    }

    public function updateProfile(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        // Check if the authenticated user is updating their own profile
        if ($request->user()->id !== (int)$id) {
            return response()->json([
                'message' => 'Unauthorized. You can only update your own profile.'
            ], 403);
        }

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // Update profile
        if (isset($validated['first_name']) || isset($validated['middle_name']) || isset($validated['last_name'])) {
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $validated['first_name'] ?? $user->profile->first_name,
                    'middle_name' => $validated['middle_name'] ?? $user->profile->middle_name,
                    'last_name' => $validated['last_name'] ?? $user->profile->last_name,
                ]
            );
        }

        // Update contact
        if (isset($validated['contact_number']) || isset($validated['address'])) {
            $user->contact()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'contact_number' => $validated['contact_number'] ?? $user->contact->contact_number ?? null,
                    'address' => $validated['address'] ?? $user->contact->address ?? null,
                ]
            );
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user->load(['profile', 'contact'])
        ], 200);
    }

    public function updatePassword(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        // Check if the authenticated user is updating their own password
        if ($request->user()->id !== (int)$id) {
            return response()->json([
                'message' => 'Unauthorized. You can only update your own password.'
            ], 403);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Verify current password
        if (!\Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors' => [
                    'current_password' => ['The current password is incorrect.']
                ]
            ], 422);
        }

        // Update password
        $user->update([
            'password' => \Hash::make($validated['new_password'])
        ]);

        return response()->json([
            'message' => 'Password updated successfully.'
        ], 200);
    }
}
