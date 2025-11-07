<?php

namespace App\Listeners;

use App\Models\ChurchSubscription;
use App\Models\Church;
use Illuminate\Contracts\Queue\ShouldQueue;

class CheckSubscriptionStatus implements ShouldQueue
{
    public function handle($event): void
    {
        // Mark expired subscriptions
        ChurchSubscription::where('Status', 'Active')
            ->where('EndDate', '<=', now())
            ->update(['Status' => 'Expired']);

        // Activate pending subscriptions
        $pendingSubscriptions = ChurchSubscription::where('Status', 'Pending')
            ->where('StartDate', '<=', now())
            ->get();

        foreach ($pendingSubscriptions as $subscription) {
            ChurchSubscription::where('UserID', $subscription->UserID)
                ->where('Status', 'Active')
                ->update(['Status' => 'Expired']);

            $subscription->update(['Status' => 'Active']);
        }

        // Unpublish churches without active subscriptions
        $userIdsWithActiveSubscription = ChurchSubscription::where('Status', 'Active')
            ->where('EndDate', '>', now())
            ->pluck('UserID')
            ->unique();

        $allChurchOwnerIds = Church::pluck('user_id')->unique();
        $userIdsWithoutActiveSubscription = $allChurchOwnerIds->diff($userIdsWithActiveSubscription);

        if ($userIdsWithoutActiveSubscription->isNotEmpty()) {
            Church::whereIn('user_id', $userIdsWithoutActiveSubscription)
                ->where('IsPublic', true)
                ->update(['IsPublic' => false]);
        }
    }
}
