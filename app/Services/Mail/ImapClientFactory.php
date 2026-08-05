<?php

namespace App\Services\Mail;

use App\Models\MailAccount;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * The one place an IMAP client is built for a MailAccount.
 *
 * Three call sites used to construct their own, and only one of them seeded
 * the manager with the app's own config. ClientManager::make() reads the
 * *manager's* top-level `options` for the client — the per-account array
 * passed to make() only carries connection details — so a bare
 * `new ClientManager` silently falls back to the package defaults and ignores
 * config/imap.php entirely. That is what ZERO-51 fixed in the sync path and
 * what the sender and the idle watcher were still doing (ZERO-96).
 */
class ImapClientFactory
{
    public function __construct(
        protected OAuthTokenRefresher $tokenRefresher,
    ) {}

    public function make(MailAccount $account): Client
    {
        $imapConfig = config('imap');
        $manager = new ClientManager(is_array($imapConfig) ? $imapConfig : []);

        $config = [
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $account->imap_encryption,
            'validate_cert' => true,
            'username' => $account->imap_username,
            // Without this, a stalled connection (wrong host, firewall
            // silently dropping packets, etc.) hangs until the caller's own
            // timeout — not something a "syncing" status should ever visibly
            // sit at.
            'timeout' => 30,
        ];

        if ($account->usesOAuth()) {
            $config['password'] = $this->tokenRefresher->freshAccessToken($account);
            $config['authentication'] = 'oauth';
        } else {
            $config['password'] = $account->imap_password;
        }

        return $manager->make($config);
    }
}
