<?php

namespace App\Providers;

use App\Jobs\UpdateSubscriptions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Start the self-queuing subscription update job (only once)
        if (Cache::missing('subscription_job_started')) {
            UpdateSubscriptions::dispatch();
            Cache::put('subscription_job_started', true, now()->addDays(30));
        }
    }
}
