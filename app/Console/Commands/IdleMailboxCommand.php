<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use App\Services\Mail\OAuthTokenRefresher;
use Illuminate\Console\Command;
use Webklex\PHPIMAP\ClientManager;

class IdleMailboxCommand extends Command
{
    protected $signature = 'mail:idle {account : MailAccount ID to watch}';

    protected $description = 'Hold an IMAP IDLE connection and dispatch a sync job when new mail arrives';

    public function handle(OAuthTokenRefresher $tokenRefresher): int
    {
        $account = MailAccount::findOrFail($this->argument('account'));

        if (! $account->is_active) {
            $this->error("Account {$account->email_address} is inactive.");

            return self::FAILURE;
        }

        $this->info("Starting IMAP IDLE for {$account->email_address}…");

        $cm = new ClientManager;

        $config = [
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $account->imap_encryption,
            'validate_cert' => true,
            'username' => $account->imap_username,
            'timeout' => 30,
        ];

        if ($account->usesOAuth()) {
            $config['password'] = $tokenRefresher->freshAccessToken($account);
            $config['authentication'] = 'oauth';
        } else {
            $config['password'] = $account->imap_password;
        }

        $client = $cm->make($config);
        $client->connect();

        $inbox = $client->getFolder('INBOX');

        // IDLE blocks until the server pushes a notification (new message,
        // flag change, expunge). We dispatch a sync job and immediately
        // re-enter IDLE — the sync handles deduplication, so triggering it
        // on any IDLE event is safe. launchd restarts us if the connection
        // drops or the server kicks us out after ~30 min.
        $inbox->idle(function () use ($account) {
            $this->line('['.now()->toTimeString().'] Activity on '.$account->email_address.' — queuing sync');
            SyncMailAccountJob::dispatch($account);
        });

        return self::SUCCESS;
    }
}
