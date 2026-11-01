<?php

namespace App\Http\Middleware;

use App\Models\OutboundToken;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttling and bearer-token authentication for POST /api/outbound
 * (ZERO-131). The per-IP count is taken before the token is looked at, so a
 * guessed token costs the caller exactly what a correct one does. Every way of
 * being refused (no header, wrong, malformed, revoked, account gone or
 * inactive) produces the identical 401, so a caller learns nothing about which
 * tokens exist.
 */
class AuthenticateOutboundToken
{
    public const ATTRIBUTE = 'outbound_token';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->hit('outbound-ip:'.$request->ip(), config()->integer('outgoing.api_ip_per_minute'), 60);

        $token = $this->resolve($request->bearerToken());

        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $this->hit('outbound-token:'.$token->id, config()->integer('outgoing.api_token_per_hour'), 3600);

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }

    protected function hit(string $key, int $max, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    protected function resolve(?string $plain): ?OutboundToken
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $hash = OutboundToken::hash($plain);
        $token = OutboundToken::query()->where('token_hash', $hash)->with('mailAccount')->first();

        if ($token === null || ! hash_equals($token->token_hash, $hash) || $token->isRevoked()) {
            return null;
        }

        return $token->mailAccount?->is_active === true ? $token : null;
    }
}
