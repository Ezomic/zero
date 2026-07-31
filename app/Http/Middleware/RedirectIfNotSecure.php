<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Herd serves this site on both http and https. Without this, a request that
 * lands on http gets a session cookie without the 'secure' flag while https
 * requests get one with it — the two are invisible to each other, so mixing
 * schemes silently breaks the session and causes CSRF (419) failures that a
 * reload can't fix. Forcing https (matching APP_URL) keeps every request on
 * one cookie.
 *
 * Exempts localhost/127.0.0.1 so `php artisan serve` still works over plain
 * http — that's used for Google OAuth testing, since Google requires a
 * verified domain for redirect URIs except for localhost.
 */
class RedirectIfNotSecure
{
    protected const EXEMPT_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $exempt = in_array($request->getHost(), self::EXEMPT_HOSTS, true);

        if (! $request->secure() && ! $exempt && str_starts_with(is_string($appUrl = config('app.url')) ? $appUrl : '', 'https://')) {
            return redirect()->secure($request->getRequestUri());
        }

        return $next($request);
    }
}
