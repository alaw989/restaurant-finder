<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        Vite::prefetch(concurrency: 3);

        // Pulse dashboard: open in local; in production only an explicit email
        // allow-list (config('pulse.admin_emails')). Registration is open, so the
        // old "$user !== null" gate let any self-registered visitor read slow SQL,
        // exception traces, and outgoing SerpApi/Overpass call telemetry.
        Gate::define('viewPulse', function ($user = null): bool {
            if (app()->environment('local')) {
                return true;
            }
            if ($user === null) {
                return false;
            }

            $allowed = array_filter(array_map('trim', explode(',', (string) config('pulse.admin_emails', ''))));

            return in_array($user->email, $allowed, true);
        });
    }
}
