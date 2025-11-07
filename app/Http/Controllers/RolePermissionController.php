<?php

namespace App\Http\Controllers;

use App\Models\Church;
use App\Models\ChurchRole;
use App\Models\Permission;
use App\Models\UserChurchRole;
use App\Models\ChurchSubscription;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    public function getChurchAndRoles(Request $request, $churchName)
    {
        // Sanitize church name by removing any unexpected suffix (e.g., ":1")
        $churchName = preg_replace('/:\d+$/', '', $churchName);
        // Convert URL-friendly church name to proper case (e.g., "st-johns" to "St Johns")
        $name = str_replace('-', ' ', ucwords($churchName, '-'));

        // Find the church by name (case-insensitive)
        $church = Church::whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])->first();

        if (!$church) {
            return response()->json(['error' => 'Church not found'], 404);
        }

        // Fetch roles for the church
        $roles = ChurchRole::where('ChurchID', $church->ChurchID)
            ->with('permissions')
            ->get();

        // Fetch staff for the church
        $staff = UserChurchRole::where('ChurchID', $church->ChurchID)
            ->with(['user.profile', 'role'])
            ->get();

        // Determine if church owner has an active subscription
        $hasActiveSubscription = ChurchSubscription::where('UserID', $church->user_id)
            ->where('Status', 'Active')
            ->where('EndDate', '>', now())
            ->exists();

        // Return combined response
        return response()->json([
            'ChurchID' => $church->ChurchID,
            'ChurchName' => $church->ChurchName,
            'ChurchStatus' => $church->ChurchStatus,
            'IsPublic' => (bool)$church->IsPublic,
            'has_active_subscription' => $hasActiveSubscription,
            'roles' => $roles,
            'staff' => $staff
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'ChurchID' => 'required|exists:Church,ChurchID',
            'RoleName' => 'required|string|max:100|unique:ChurchRole,RoleName,NULL,RoleID,ChurchID,' . $request->ChurchID,
            'permissions' => 'array',
            'permissions.*' => 'exists:Permission,PermissionID',
        ]);

        $role = ChurchRole::create([
            'ChurchID' => $validated['ChurchID'],
            'RoleName' => $validated['RoleName'],
        ]);

        if (!empty($validated['permissions'])) {
            $role->permissions()->attach($validated['permissions']);
        }

        return response()->json(['message' => 'Role created successfully', 'role' => $role->load('permissions')], 201);
    }

    public function show(Request $request, $roleId)
    {
        $churchId = $request->query('church_id');
        $role = ChurchRole::where('ChurchID', $churchId)->findOrFail($roleId);
        return response()->json($role->load('permissions'));
    }

    public function update(Request $request, $roleId)
    {
        $validated = $request->validate([
            'ChurchID' => 'required|exists:Church,ChurchID',
            'RoleName' => 'required|string|max:100|unique:ChurchRole,RoleName,' . $roleId . ',RoleID,ChurchID,' . $request->ChurchID,
            'permissions' => 'array',
            'permissions.*' => 'exists:Permission,PermissionID',
        ]);

        $role = ChurchRole::where('ChurchID', $validated['ChurchID'])->findOrFail($roleId);
        $role->update(['RoleName' => $validated['RoleName']]);
        $role->permissions()->sync($validated['permissions'] ?? []);

        return response()->json(['message' => 'Role updated successfully', 'role' => $role->load('permissions')]);
    }

    public function getPermissions()
    {
        $permissions = Permission::all(['PermissionID', 'PermissionName']);
        return response()->json($permissions);
    }
}