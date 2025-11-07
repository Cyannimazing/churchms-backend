<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;
use App\Models\Church;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Church staff channel - only staff members can listen to church notifications
Broadcast::channel('church.{churchId}', function ($user, $churchId) {
    // Check if user is church owner
    $isOwner = Church::where('ChurchID', $churchId)
        ->where('ChurchOwnerID', $user->id)
        ->exists();
    
    if ($isOwner) {
        return true;
    }
    
    // Check if user is a staff member
    $isStaff = \App\Models\UserChurchRole::where('user_id', $user->id)
        ->whereHas('role', function($query) use ($churchId) {
            $query->where('ChurchID', $churchId);
        })
        ->where('is_active', true)
        ->exists();
    
    return $isStaff;
});

// User notification channel - users can only listen to their own notifications
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
