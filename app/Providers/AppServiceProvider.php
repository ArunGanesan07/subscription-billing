<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Surface lazy loading (N+1) and silently-dropped attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Keyed by merchant (i.e. per API key), not by IP: one tenant's burst
        // can't starve another, and a merchant can't dodge it by spreading IPs.
        RateLimiter::for('usage', fn (Request $request) => Limit::perMinute(config('billing.usage.rate_limit_per_minute'))
            ->by('usage:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by('api:'.($request->user()?->id ?? $request->ip())));
    }
}
