<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Console\Commands\UpdateSubscriptionsCommand;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Removed EnsureFrontendRequestsAreStateful for token-based authentication
        // This middleware enforces CSRF and session-based auth

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);
        
        // Add CORS fix middleware to prevent duplicate headers
        $middleware->append(\App\Http\Middleware\ForceCorsSingleOrigin::class);

        //
    })
    ->withCommands([
        UpdateSubscriptionsCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
