<?php

namespace App\Providers;

use App\Auth\AccountActivity;
use App\Auth\DtsUserProvider;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use App\View\Components\OrchidIcon;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Validated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
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

        Event::subscribe(AccountActivity::class);
        Event::listen(Validated::class, [EnsureAccountIsActive::class, 'refuseSignIn']);

        // Sign-in accepts the real password or a pending temporary one (forgot password); after sign-in only the one
        // that was used is kept.
        Auth::provider('dts-users', fn ($app, array $config): DtsUserProvider => new DtsUserProvider($app['hash'], $config['model']));
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->settleTemporaryPasswordAfterSignIn();
            }
        });

        // Every new or changed staff password: at least 13 characters with a capital letter, a small letter, a number
        // and a symbol. Passwords set before this rule keep working until they are next changed.
        Password::defaults(fn (): Password => Password::min(User::PASSWORD_MIN_LENGTH)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols());
    }
}
