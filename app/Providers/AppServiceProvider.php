<?php

namespace App\Providers;

use App\View\Components\OrchidIcon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Orchid\Icons\IconComponent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(IconComponent::class, OrchidIcon::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The live server is HTTPS only (ISO/IEC 27002 8.24, protected transmission); local development keeps http.
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }

        // Staff background requests (lookups, saves, attachments, side panel). Normal use stays far below this;
        // it only stops a runaway script or tab. Page loads are left out because Turbo prefetches links on hover.
        RateLimiter::for('staff-requests', fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
