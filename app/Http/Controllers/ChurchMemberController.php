<?php

namespace App\Http\Controllers;

use App\Models\ChurchMember;
use App\Models\Church;
use App\Models\MemberChild;
use App\Models\Notification;
use App\Models\UserChurchRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Events\MemberApplicationCreated;
use App\Events\MemberApplicationStatusUpdated;
use App\Events\NotificationCreated;

class ChurchMemberController extends Controller
{
    public function index(Request $request)
    {
        $query = ChurchMember::with(['church', 'user', 'children']);

        // Filter by church if user is church staff
        if ($request->has('church_id')) {
            $query->where('church_id', $request->church_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $members = $query->paginate(15);

        return response()->json($members);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Parish Registration
            'church_id' => 'required|exists:Church,ChurchID',
            'first_name' => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:1',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'contact_number' => 'nullable|string|max:20',
            'street_address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'province' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'barangay' => 'nullable|string|max:100',
            'financial_support' => 'nullable|in:Weekly Collection,Monthly Envelope,Bank Transfer,GCash/PayMaya',
            
            // Head of House
            'head_first_name' => 'required|string|max:255',
            'head_middle_initial' => 'nullable|string|max:1',
            'head_last_name' => 'required|string|max:255',
            'head_date_of_birth' => 'required|date',
            'head_phone_number' => 'required|string|max:20',
            'head_email_address' => 'required|email|max:255',
            'head_religion' => 'required|string|max:255',
            'head_baptism' => 'boolean',
            'head_first_eucharist' => 'boolean',
            'head_confirmation' => 'boolean',
            'head_marital_status' => 'required|in:Single,Married,Widowed,Divorced',
            'head_catholic_marriage' => 'required|boolean',
            
            // Spouse
            'spouse_first_name' => 'nullable|string|max:255',
            'spouse_middle_initial' => 'nullable|string|max:1',
            'spouse_last_name' => 'nullable|string|max:255',
            'spouse_date_of_birth' => 'nullable|date',
            'spouse_phone_number' => 'nullable|string|max:20',
            'spouse_email_address' => 'nullable|email|max:255',
            'spouse_religion' => 'nullable|string|max:255',
            'spouse_baptism' => 'boolean',
            'spouse_first_eucharist' => 'boolean',
            'spouse_confirmation' => 'boolean',
            'spouse_marital_status' => 'nullable|in:Single,Married,Widowed,Divorced',
            'spouse_catholic_marriage' => 'nullable|boolean',
            
            // About Yourself
            'talent_to_share' => 'nullable|string',
            'interested_ministry' => 'nullable|string',
            'parish_help_needed' => 'nullable|string',
            'homebound_special_needs' => 'boolean',
            'other_languages' => 'nullable|string|max:255',
            'ethnicity' => 'nullable|string|max:255',
            
            // Children
            'children' => 'nullable|array',
            'children.*.first_name' => 'required|string|max:255',
            'children.*.last_name' => 'nullable|string|max:255',
            'children.*.date_of_birth' => 'required|date',
            'children.*.sex' => 'required|in:M,F',
            'children.*.religion' => 'nullable|string|max:255',
            'children.*.baptism' => 'boolean',
            'children.*.first_eucharist' => 'boolean',
            'children.*.confirmation' => 'boolean',
            'children.*.school' => 'nullable|string|max:255',
            'children.*.grade' => 'nullable|string|max:50',
        ]);

        // Check if user already has an approved membership anywhere (including this church)
        $userId = Auth::id();
        $existingApprovedMembership = ChurchMember::where('user_id', $userId)
            ->where('status', 'approved')
            ->exists();

        if ($existingApprovedMembership) {
            return response()->json([
                'message' => 'You already have an approved membership. You can only be an active member of one church at a time. Please contact staff to mark your current membership as "Away" if you want to switch churches.',
                'error' => 'MEMBERSHIP_ALREADY_EXISTS'
            ], 422);
        }

        // Check if user already has a pending application for this church
        $existingPendingApplication = ChurchMember::where('user_id', $userId)
            ->where('church_id', $validated['church_id'])
            ->where('status', 'pending')
            ->exists();

        if ($existingPendingApplication) {
            return response()->json([
                'message' => 'You already have a pending application for this church.',
                'error' => 'PENDING_APPLICATION_EXISTS'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Add user_id if authenticated
            $validated['user_id'] = Auth::id();

            // Extract children data
            $children = $validated['children'] ?? [];
            unset($validated['children']);

            // Create member
            $member = ChurchMember::create($validated);

            // Create children records
            if (!empty($children)) {
                foreach ($children as $childData) {
                    $member->children()->create($childData);
                }
            }

            // Notify all church staff + owner
            $church = Church::find($validated['church_id']);
            $staffUserIds = UserChurchRole::where('ChurchID', $validated['church_id'])
                ->pluck('user_id');
            
            // Add church owner
            if ($church && $church->user_id) {
                $staffUserIds->push($church->user_id);
            }
            $staffUserIds = $staffUserIds->unique();
            
            $applicantName = trim($validated['first_name'] . ' ' . $validated['last_name']);
            $churchName = $church ? $church->ChurchName : 'your church';
            $churchNameSlug = $church ? strtolower(str_replace(' ', '-', $church->ChurchName)) : 'church';
            
            foreach ($staffUserIds as $staffUserId) {
                $notification = Notification::create([
                    'user_id' => $staffUserId,
                    'type' => 'member_application',
                    'title' => 'New Member Application',
                    'message' => "{$applicantName} has submitted a membership application to {$churchName}.",
                    'data' => [
                        'application_id' => $member->id,
                        'applicant_name' => $applicantName,
                        'church_id' => $validated['church_id'],
                        'church_name' => $churchName,
                        'status' => 'pending',
                        'link' => "/{$churchNameSlug}/member-applications?applicationId={$member->id}&status=pending",
                    ],
                    'is_read' => false,
                ]);
                
                // Broadcast to each staff member's private channel
                broadcast(new NotificationCreated($staffUserId, $notification));
            }
            
            // Broadcast to church channel for real-time updates
            broadcast(new MemberApplicationCreated($member, $validated['church_id'], null));

            DB::commit();

            return response()->json([
                'message' => 'Member application submitted successfully',
                'member' => $member->load('children')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error submitting application',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(ChurchMember $churchMember)
    {
        return response()->json($churchMember->load(['church', 'user', 'children', 'approvedBy']));
    }

    public function update(Request $request, ChurchMember $churchMember)
    {
        $validated = $request->validate([
            // Parish Registration
            'first_name' => 'sometimes|string|max:255',
            'middle_initial' => 'sometimes|nullable|string|max:1',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'contact_number' => 'sometimes|nullable|string|max:20',
            'street_address' => 'sometimes|string|max:255',
            'city' => 'sometimes|string|max:255',
            'province' => 'sometimes|string|max:100',
            'postal_code' => 'sometimes|nullable|string|max:20',
            'barangay' => 'sometimes|nullable|string|max:100',
            'apt_unit_number' => 'sometimes|nullable|string|max:100',
            'financial_support' => 'sometimes|nullable|in:Weekly Collection,Monthly Envelope,Bank Transfer,GCash/PayMaya',
            
            // Head of House
            'head_first_name' => 'sometimes|string|max:255',
            'head_middle_initial' => 'sometimes|nullable|string|max:1',
            'head_last_name' => 'sometimes|string|max:255',
            'head_maiden_name' => 'sometimes|nullable|string|max:255',
            'head_date_of_birth' => 'sometimes|date',
            'head_phone_number' => 'sometimes|string|max:20',
            'head_email_address' => 'sometimes|email|max:255',
            'head_religion' => 'sometimes|string|max:255',
            'head_baptism' => 'sometimes|boolean',
            'head_first_eucharist' => 'sometimes|boolean',
            'head_confirmation' => 'sometimes|boolean',
            'head_marital_status' => 'sometimes|in:Single,Married,Widowed,Divorced',
            'head_catholic_marriage' => 'sometimes|boolean',
            
            // Spouse
            'spouse_first_name' => 'sometimes|nullable|string|max:255',
            'spouse_middle_initial' => 'sometimes|nullable|string|max:1',
            'spouse_last_name' => 'sometimes|nullable|string|max:255',
            'spouse_maiden_name' => 'sometimes|nullable|string|max:255',
            'spouse_date_of_birth' => 'sometimes|nullable|date',
            'spouse_phone_number' => 'sometimes|nullable|string|max:20',
            'spouse_email_address' => 'sometimes|nullable|email|max:255',
            'spouse_religion' => 'sometimes|nullable|string|max:255',
            'spouse_baptism' => 'sometimes|boolean',
            'spouse_first_eucharist' => 'sometimes|boolean',
            'spouse_confirmation' => 'sometimes|boolean',
            'spouse_marital_status' => 'sometimes|nullable|in:Single,Married,Widowed,Divorced',
            'spouse_catholic_marriage' => 'sometimes|nullable|boolean',
            
            // About Yourself
            'talent_to_share' => 'sometimes|nullable|string',
            'interested_ministry' => 'sometimes|nullable|string',
            'parish_help_needed' => 'sometimes|nullable|string',
            'homebound_special_needs' => 'sometimes|boolean',
            'other_languages' => 'sometimes|nullable|string|max:255',
            'ethnicity' => 'sometimes|nullable|string|max:255',
            
            // Children
            'children' => 'sometimes|nullable|array',
            'children.*.id' => 'sometimes|exists:member_children,id',
            'children.*.first_name' => 'required_with:children|string|max:255',
            'children.*.last_name' => 'sometimes|nullable|string|max:255',
            'children.*.date_of_birth' => 'required_with:children|date',
            'children.*.sex' => 'required_with:children|in:M,F',
            'children.*.religion' => 'sometimes|nullable|string|max:255',
            'children.*.baptism' => 'sometimes|boolean',
            'children.*.first_eucharist' => 'sometimes|boolean',
            'children.*.confirmation' => 'sometimes|boolean',
            'children.*.school' => 'sometimes|nullable|string|max:255',
            'children.*.grade' => 'sometimes|nullable|string|max:50',
            
            // Status and notes
            'status' => 'sometimes|in:pending,approved,rejected,away',
            'notes' => 'sometimes|nullable|string',
        ]);

        if (isset($validated['status'])) {
            if ($validated['status'] === 'approved') {
                // Check if user has another approved membership
                $existingApprovedMembership = ChurchMember::where('user_id', $churchMember->user_id)
                    ->where('status', 'approved')
                    ->where('id', '!=', $churchMember->id)
                    ->exists();

                if ($existingApprovedMembership) {
                    return response()->json([
                        'message' => 'This user already has an approved membership in another church. Set their current membership to "Away" first.',
                        'error' => 'MULTIPLE_MEMBERSHIP_NOT_ALLOWED'
                    ], 422);
                }

                $validated['approved_at'] = now();
                $validated['approved_by'] = Auth::id();
            }
        }

        DB::beginTransaction();
        
        try {
            // Extract children data
            $children = $validated['children'] ?? null;
            unset($validated['children']);
            
            // Update member
            $churchMember->update($validated);
            
            // Handle children updates if provided
            if ($children !== null) {
                // Delete existing children
                $churchMember->children()->delete();
                
                // Create new children records
                foreach ($children as $childData) {
                    unset($childData['id']); // Remove ID if present to create new record
                    $churchMember->children()->create($childData);
                }
            }
            
            DB::commit();
            
            return response()->json($churchMember->fresh('children'));
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating member information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(ChurchMember $churchMember)
    {
        $churchMember->delete();

        return response()->json([
            'message' => 'Member record deleted successfully'
        ]);
    }

    public function getByChurch($churchId)
    {
        $members = ChurchMember::where('church_id', $churchId)
            ->with(['user', 'children'])
            ->paginate(15);

        return response()->json($members);
    }

    public function getApplications(Request $request)
    {
        $query = ChurchMember::with(['church', 'user', 'children'])
            ->where('status', 'pending');

        if ($request->has('church_id')) {
            $query->where('church_id', $request->church_id);
        }

        $applications = $query->paginate(15);

        return response()->json($applications);
    }

    public function approveApplication(Request $request, ChurchMember $churchMember)
    {
        // Check if user has another approved membership
        $existingApprovedMembership = ChurchMember::where('user_id', $churchMember->user_id)
            ->where('status', 'approved')
            ->where('id', '!=', $churchMember->id)
            ->exists();

        if ($existingApprovedMembership) {
            return response()->json([
                'message' => 'This user already has an approved membership in another church. Set their current membership to "Away" first.',
                'error' => 'MULTIPLE_MEMBERSHIP_NOT_ALLOWED'
            ], 422);
        }

        $churchMember->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => Auth::id(),
            'notes' => $request->notes
        ]);

        // Notify the applicant
        if ($churchMember->user_id) {
            $applicantName = trim($churchMember->first_name . ' ' . $churchMember->last_name);
            $church = Church::find($churchMember->church_id);
            $churchName = $church ? $church->ChurchName : 'the church';
            
            $notification = Notification::create([
                'user_id' => $churchMember->user_id,
                'type' => 'member_application_approved',
                'title' => 'Membership Application Approved',
                'message' => "Your membership application to {$churchName} has been approved!",
                'data' => [
                    'application_id' => $churchMember->id,
                    'applicant_name' => $applicantName,
                    'church_id' => $churchMember->church_id,
                    'church_name' => $churchName,
                    'status' => 'approved',
                    'link' => "/profile/memberships",
                ],
                'is_read' => false,
            ]);
            
            // Broadcast to applicant's private channel
            broadcast(new NotificationCreated($churchMember->user_id, $notification));
        }
        
        // Broadcast to church channel for real-time updates
        broadcast(new MemberApplicationStatusUpdated($churchMember, $churchMember->church_id, 'approved', null));

        return response()->json([
            'message' => 'Member application approved successfully',
            'member' => $churchMember->fresh()
        ]);
    }

    public function rejectApplication(Request $request, ChurchMember $churchMember)
    {
        // Notify the applicant before deletion
        if ($churchMember->user_id) {
            $applicantName = trim($churchMember->first_name . ' ' . $churchMember->last_name);
            $church = Church::find($churchMember->church_id);
            $churchName = $church ? $church->ChurchName : 'the church';
            
            $notification = Notification::create([
                'user_id' => $churchMember->user_id,
                'type' => 'member_application_rejected',
                'title' => 'Membership Application Rejected',
                'message' => "Your membership application to {$churchName} has been rejected. Please contact the church for more information.",
                'data' => [
                    'application_id' => $churchMember->id,
                    'applicant_name' => $applicantName,
                    'church_id' => $churchMember->church_id,
                    'church_name' => $churchName,
                    'status' => 'rejected',
                    'link' => "/profile/memberships",
                ],
                'is_read' => false,
            ]);
            
            // Broadcast to applicant's private channel
            broadcast(new NotificationCreated($churchMember->user_id, $notification));
        }
        
        // Broadcast to church channel for real-time updates before deletion
        broadcast(new MemberApplicationStatusUpdated($churchMember, $churchMember->church_id, 'rejected', null));
        
        $churchMember->delete();

        return response()->json([
            'message' => 'Member application rejected and deleted successfully'
        ]);
    }

    /**
     * Set member status to 'away' - allows them to register at another church
     */
    public function setAway(Request $request, ChurchMember $churchMember)
    {
        $churchMember->update([
            'status' => 'away',
            'notes' => $request->notes ?? 'Member status set to away - can register at another church'
        ]);

        // Get member and church info for notifications
        $memberName = trim($churchMember->first_name . ' ' . $churchMember->last_name);
        $church = Church::find($churchMember->church_id);
        $churchName = $church ? $church->ChurchName : 'the church';
        $churchNameSlug = $church ? strtolower(str_replace(' ', '-', $church->ChurchName)) : 'church';

        // 1. Notify the member
        if ($churchMember->user_id) {
            $memberNotification = Notification::create([
                'user_id' => $churchMember->user_id,
                'type' => 'member_kicked',
                'title' => 'Membership Status Changed',
                'message' => "Your membership at {$churchName} has been set to 'Away'. You can now register at another church.",
                'data' => [
                    'member_id' => $churchMember->id,
                    'member_name' => $memberName,
                    'church_id' => $churchMember->church_id,
                    'church_name' => $churchName,
                    'status' => 'away',
                    'link' => '/profile/memberships',
                ],
                'is_read' => false,
            ]);
            
            // Broadcast to member's private channel
            broadcast(new NotificationCreated($churchMember->user_id, $memberNotification));
        }

        // 2. Notify church owner
        if ($church && $church->user_id) {
            $ownerNotification = Notification::create([
                'user_id' => $church->user_id,
                'type' => 'member_kicked',
                'title' => 'Member Kicked',
                'message' => "{$memberName} has been removed from {$churchName} and can now register at another church.",
                'data' => [
                    'member_id' => $churchMember->id,
                    'member_name' => $memberName,
                    'church_id' => $churchMember->church_id,
                    'church_name' => $churchName,
                    'status' => 'away',
                    'link' => "/{$churchNameSlug}/member-directory",
                ],
                'is_read' => false,
            ]);
            
            // Broadcast to owner's private channel
            broadcast(new NotificationCreated($church->user_id, $ownerNotification));
        }

        // 3. Notify all church staff
        $staffUserIds = UserChurchRole::where('ChurchID', $churchMember->church_id)
            ->pluck('user_id');
        
        foreach ($staffUserIds as $staffUserId) {
            // Skip if this is the church owner (already notified)
            if ($church && $staffUserId == $church->user_id) {
                continue;
            }
            
            $staffNotification = Notification::create([
                'user_id' => $staffUserId,
                'type' => 'member_kicked',
                'title' => 'Member Kicked',
                'message' => "{$memberName} has been removed from {$churchName} and can now register at another church.",
                'data' => [
                    'member_id' => $churchMember->id,
                    'member_name' => $memberName,
                    'church_id' => $churchMember->church_id,
                    'church_name' => $churchName,
                    'status' => 'away',
                    'link' => "/{$churchNameSlug}/member-directory",
                ],
                'is_read' => false,
            ]);
            
            // Broadcast to staff member's private channel
            broadcast(new NotificationCreated($staffUserId, $staffNotification));
        }

        return response()->json([
            'message' => 'Member status set to away successfully. They can now register at another church.',
            'member' => $churchMember->fresh()
        ]);
    }

    /**
     * Get member's current status across all churches
     */
    public function getMemberStatus(Request $request)
    {
        $userId = Auth::id();
        $memberships = ChurchMember::where('user_id', $userId)
            ->with('church')
            ->orderBy('created_at', 'desc')
            ->get();

        $currentApprovedMembership = $memberships->where('status', 'approved')->first();
        $canRegister = !$currentApprovedMembership;

        return response()->json([
            'memberships' => $memberships,
            'current_approved_membership' => $currentApprovedMembership,
            'can_register_new_church' => $canRegister,
            'message' => $canRegister ? 'You can register for a new church' : 'You have an active membership. Set it to "Away" to register elsewhere.'
        ]);
    }

    /**
     * Check if the current user is a member of a specific church
     */
    public function getUserMembership($churchId)
    {
        $userId = Auth::id();
        
        $membership = ChurchMember::where('user_id', $userId)
            ->where('church_id', $churchId)
            ->with('church')
            ->first();
        
        if (!$membership) {
            return response()->json(null, 404);
        }
        
        return response()->json([
            'id' => $membership->id,
            'status' => $membership->status,
            'approved_at' => $membership->approved_at,
            'church' => [
                'ChurchID' => $membership->church->ChurchID,
                'ChurchName' => $membership->church->ChurchName
            ]
        ]);
    }
}
