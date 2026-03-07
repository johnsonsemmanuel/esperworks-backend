<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
    
    public function register(): void
    {
        //
    }
}
