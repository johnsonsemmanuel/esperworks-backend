<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;

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
        // Register event listeners for client notifications
        Event::listen(
            \App\Events\InvoiceSent::class,
            \App\Listeners\SendClientNotification::class
        );

        Event::listen(
            \App\Events\PaymentReceived::class,
            \App\Listeners\SendClientNotification::class
        );

        Event::listen(
            \App\Events\ClientContractResponse::class,
            \App\Listeners\CreateDraftInvoiceOnProposalAccepted::class
        );

        // Delegate scoping to the controllers or implicit scoped bindings.
        Route::bind('invoice', function ($value) {
            return \App\Models\Invoice::findOrFail($value);
        });
        Route::bind('contract', function ($value) {
            return \App\Models\Contract::findOrFail($value);
        });

        // Force HTTPS in production
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
        
        // Set secure cookie settings in production
        if (app()->environment('production')) {
            config([
                'session.secure' => true,
                'session.http_only' => true,
                'session.same_site' => 'strict',
            ]);
        }
    }
}
