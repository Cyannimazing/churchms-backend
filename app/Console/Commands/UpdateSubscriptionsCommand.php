<?php

namespace App\Console\Commands;

use App\Models\ChurchSubscription;
use App\Models\Church;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:update';
    protected $description = 'Update subscription statuses: mark expired, activate pending, and unpublish churches without active subscriptions';

    public function handle()
    {
        // Mark active subscriptions as expired
        $expiredCount = ChurchSubscription::where('Status', 'Active')
            ->where('EndDate', '<=', now())
            ->update(['Status' => 'Expired']);

        // Activate pending subscriptions
        $pendingSubscriptions = ChurchSubscription::where('Status', 'Pending')
            ->where('StartDate', '<=', now())
            ->get();

        $activatedCount = 0;
        foreach ($pendingSubscriptions as $subscription) {
            // Ensure no other active subscription exists
            ChurchSubscription::where('UserID', $subscription->UserID)
                ->where('Status', 'Active')
                ->update(['Status' => 'Expired']);

            $subscription->update(['Status' => 'Active']);
            $activatedCount++;
        }

        // Unpublish churches without active subscriptions
        $userIdsWithActiveSubscription = ChurchSubscription::where('Status', 'Active')
            ->where('EndDate', '>', now())
            ->pluck('UserID')
            ->unique();

        $allChurchOwnerIds = Church::pluck('user_id')->unique();
        $userIdsWithoutActiveSubscription = $allChurchOwnerIds->diff($userIdsWithActiveSubscription);

        $unpublishedCount = 0;
        if ($userIdsWithoutActiveSubscription->isNotEmpty()) {
            $unpublishedCount = Church::whereIn('user_id', $userIdsWithoutActiveSubscription)
                ->where('IsPublic', true)
                ->update(['IsPublic' => false]);
        }

        $this->info("Subscription statuses updated successfully.");
        $this->info("Expired: {$expiredCount}, Activated: {$activatedCount}, Churches unpublished: {$unpublishedCount}");
    }
}
