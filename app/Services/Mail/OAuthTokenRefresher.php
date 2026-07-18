<?php

namespace App\Services\Mail;

use App\Models\MailAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Keeps a MailAccount's OAuth access token fresh. Access tokens for both
 * Google and Microsoft expire (~1 hour); we exchange the stored refresh
 * token for a new access token whenever it's expired or about to expire.
 */
class OAuthTokenRefresher
{
    public function freshAccessToken(MailAccount $account): string
    {
        if (! $account->tokenIsExpired() && $account->oauth_access_token) {
            return $account->oauth_access_token;
        }

        return match ($account->provider) {
            MailAccount::PROVIDER_GMAIL => $this->refreshGoogle($account),
            MailAccount::PROVIDER_OUTLOOK => $this->refreshMicrosoft($account),
            default => throw new RuntimeException("Account {$account->id} does not use OAuth."),
        };
    }

    protected function refreshGoogle(MailAccount $account): string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $account->oauth_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            $account->update(['sync_status' => 'error', 'sync_error' => 'Google token refresh failed: '.$response->body()]);
            throw new RuntimeException('Google token refresh failed for account '.$account->id);
        }

        $data = $response->json();

        $account->update([
            'oauth_access_token' => $data['access_token'],
            'oauth_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    protected function refreshMicrosoft(MailAccount $account): string
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'refresh_token' => $account->oauth_refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'offline_access Mail.ReadWrite Mail.Send',
        ]);

        if ($response->failed()) {
            $account->update(['sync_status' => 'error', 'sync_error' => 'Microsoft token refresh failed: '.$response->body()]);
            throw new RuntimeException('Microsoft token refresh failed for account '.$account->id);
        }

        $data = $response->json();

        $account->update([
            'oauth_access_token' => $data['access_token'],
            // Microsoft rotates refresh tokens on most requests.
            'oauth_refresh_token' => $data['refresh_token'] ?? $account->oauth_refresh_token,
            'oauth_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        return $data['access_token'];
    }

    /**
     * Build the base64 XOAUTH2 SASL string IMAP/SMTP servers expect.
     */
    public function buildXOAuth2Token(string $emailAddress, string $accessToken): string
    {
        $raw = "user={$emailAddress}\x01auth=Bearer {$accessToken}\x01\x01";

        return base64_encode($raw);
    }
}
