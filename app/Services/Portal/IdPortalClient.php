<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches the apps a user may open from Thijssensoftware ID, for the in-app
 * portal / app switcher. Talks to ID's client-credentials endpoint and fails
 * soft: any error yields an empty list so the switcher just shows nothing
 * rather than breaking the page.
 */
class IdPortalClient
{
    private const TOKEN_CACHE_KEY = 'portal-client-token';

    /**
     * @return list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string, current: bool}>
     */
    public function appsFor(User $user): array
    {
        if (! $this->configured()) {
            return [];
        }

        $key = 'portal-apps:'.sha1($user->email);

        $cached = Cache::get($key);

        if (! is_array($cached)) {
            $cached = $this->fetch($user);

            // A transient failure returns null: don't cache it, so the next
            // request retries rather than serving an empty list for the TTL.
            if ($cached === null) {
                return [];
            }

            Cache::put($key, $cached, Config::integer('services.thijssensoftware.portal_cache_ttl', 300));
        }

        /** @var list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string}> $cached */
        $currentSlug = Config::string('services.thijssensoftware.slug');

        return array_map(
            fn (array $app): array => [...$app, 'current' => $app['slug'] === $currentSlug],
            $cached,
        );
    }

    /**
     * @return list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string}>|null
     */
    private function fetch(User $user): ?array
    {
        try {
            $token = $this->token();

            if ($token === null) {
                return null;
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($this->url('/api/portal/apps'), ['email' => $user->email]);

            if ($response->failed()) {
                return null;
            }

            /** @var list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string}> $apps */
            $apps = $response->json('applications', []);

            return $apps;
        } catch (Throwable) {
            return null;
        }
    }

    private function token(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $response = Http::acceptJson()->asForm()->post($this->url('/oauth/token'), [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.thijssensoftware.client_id'),
            'client_secret' => config('services.thijssensoftware.client_secret'),
        ]);

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        $ttl = is_numeric($expiresIn) ? (int) $expiresIn : 600;

        if (! is_string($token)) {
            return null;
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $ttl - 30));

        return $token;
    }

    private function configured(): bool
    {
        return filled(config('services.thijssensoftware.base_url'))
            && filled(config('services.thijssensoftware.client_id'))
            && filled(config('services.thijssensoftware.client_secret'));
    }

    private function url(string $path): string
    {
        return rtrim(Config::string('services.thijssensoftware.base_url'), '/').$path;
    }
}
