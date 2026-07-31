<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

class GoogleOAuthController extends Controller
{
    /**
     * Redirect the user to Google's consent screen.
     * 'access_type=offline' + 'prompt=consent' are required to reliably get a refresh_token.
     */
    public function redirect(): RedirectResponse
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('google');

        return $provider
            ->scopes([
                'https://mail.google.com/', // full IMAP/SMTP access
                'openid',
                'email',
                'profile',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        if (! $googleUser->refreshToken) {
            return redirect()
                ->route('accounts.index')
                ->with('error', 'Google did not return a refresh token. Revoke app access at myaccount.google.com/permissions and try connecting again.');
        }

        MailAccount::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'email_address' => $googleUser->getEmail(),
            ],
            [
                'display_name' => $googleUser->getName(),
                'provider' => MailAccount::PROVIDER_GMAIL,
                'imap_host' => 'imap.gmail.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'imap_username' => $googleUser->getEmail(),
                'smtp_host' => 'smtp.gmail.com',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => $googleUser->getEmail(),
                'oauth_access_token' => $googleUser->token,
                'oauth_refresh_token' => $googleUser->refreshToken,
                'oauth_expires_at' => now()->addSeconds(is_numeric($googleUser->expiresIn ?? null) ? (int) $googleUser->expiresIn : 3600),
                'is_active' => true,
            ]
        );

        return redirect()->route('accounts.index')->with('status', 'Gmail account connected.');
    }
}
