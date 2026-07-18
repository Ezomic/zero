<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

class MicrosoftOAuthController extends Controller
{
    /**
     * Redirect to Microsoft's consent screen (works for outlook.com, hotmail.com,
     * live.com, and Microsoft 365 accounts).
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('graph')
            ->scopes([
                'openid',
                'email',
                'profile',
                'offline_access',
                'Mail.ReadWrite',
                'Mail.Send',
            ])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $msUser = Socialite::driver('graph')->user();

        if (! $msUser->refreshToken) {
            return redirect()
                ->route('accounts.index')
                ->with('error', 'Microsoft did not return a refresh token. Try connecting again.');
        }

        MailAccount::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'email_address' => $msUser->getEmail(),
            ],
            [
                'display_name' => $msUser->getName(),
                'provider' => MailAccount::PROVIDER_OUTLOOK,
                'imap_host' => 'outlook.office365.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'imap_username' => $msUser->getEmail(),
                'smtp_host' => 'smtp.office365.com',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => $msUser->getEmail(),
                'oauth_access_token' => $msUser->token,
                'oauth_refresh_token' => $msUser->refreshToken,
                'oauth_expires_at' => now()->addSeconds($msUser->expiresIn ?? 3600),
                'is_active' => true,
            ]
        );

        return redirect()->route('accounts.index')->with('status', 'Outlook/Hotmail account connected.');
    }
}
