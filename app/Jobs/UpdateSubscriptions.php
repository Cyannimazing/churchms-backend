<?php

namespace App\Jobs;

use App\Models\ChurchSubscription;
use App\Models\Church;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateSubscriptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
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

        // Re-queue itself to run again in 60 seconds
        self::dispatch()->delay(now()->addSeconds(60));
    }
}
