<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Support\UnsubscribeOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Getting off a mailing list without leaving the inbox (ZERO-115).
 *
 * Only the RFC 8058 one-click POST happens here. The plain https link and the
 * mailto: form are rendered as ordinary links for the user to follow
 * themselves, because both of those mean handing the sender either a browser
 * session or an outgoing message, and neither is something the server should
 * do on its own.
 */
class UnsubscribeController extends Controller
{
    /** Long enough for a slow endpoint, short enough not to hold the request. */
    protected const TIMEOUT = 8;

    public function store(Email $email): RedirectResponse
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        $options = $email->unsubscribeOptions();

        if (! $options instanceof UnsubscribeOptions || ! $options->oneClick) {
            return back()->with('error', 'This message has no one-click unsubscribe link.');
        }

        // Separated from the case above so the reason is not misreported: the
        // sender did offer one-click, it just points somewhere that cannot be
        // posted to safely.
        if (! $options->isPostable()) {
            return back()->with('error', "That unsubscribe link could not be verified, so nothing was sent. Use the sender's own page instead.");
        }

        return $this->post($email, (string) $options->url);
    }

    private function post(Email $email, string $url): RedirectResponse
    {
        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT)
                // No redirects: a 30x is the sender pointing us somewhere the
                // guard in UnsubscribeOptions never got to check.
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'Zero Mail'])
                ->post($url, ['List-Unsubscribe' => 'One-Click']);
        } catch (ConnectionException $e) {
            Log::warning('Unsubscribe POST failed', ['email_id' => $email->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not reach the unsubscribe endpoint. Try the link instead.');
        }

        if (! $response->successful()) {
            Log::warning('Unsubscribe POST rejected', ['email_id' => $email->id, 'status' => $response->status()]);

            return back()->with('error', "The sender's server refused the request ({$response->status()}). Try the link instead.");
        }

        // The list operator is under no obligation to have stopped sending
        // yet, so the honest claim is what was sent, not what will arrive.
        return back()->with('status', 'Unsubscribe request sent to '.$email->from_address.'.');
    }
}
