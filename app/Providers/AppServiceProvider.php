<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use SocialiteProviders\Graph\GraphExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        Event::listen(SocialiteWasCalled::class, GraphExtendSocialite::class);

        RateLimiter::for('login', function (Request $request) {
            $limits = [Limit::perMinute(5)->by('ip:'.$request->ip())];
            $email = Str::lower(trim($request->string('email')->toString()));

            if ($email !== '') {
                // Spreading guesses over many IPs still lands on one address (ZERO-135).
                $limits[] = Limit::perMinute(5)->by('email:'.$email);
            }

            return $limits;
        });
    }
}
