<?php

namespace App\Console\Commands;

use App\Exceptions\AccountWatchStoppedException;
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
        if (! config('features.imap_idle')) {
            $this->warn('IMAP IDLE is disabled (FEATURE_IMAP_IDLE=false). Exiting.');

            return self::SUCCESS;
        }

        $account = MailAccount::find($this->argument('account'));

        if (! $account) {
            $this->info("Account {$this->argument('account')} no longer exists — exiting.");

            return self::SUCCESS;
        }

        if (! $account->is_active) {
            $this->info("Account {$account->email_address} is inactive — exiting.");

            return self::SUCCESS;
        }

        if ($account->provider === MailAccount::PROVIDER_OUTLOOK) {
            $this->warn("Account {$account->email_address} is Outlook — reads via Microsoft Graph, which has no IMAP IDLE equivalent. Exiting.");

            return self::SUCCESS;
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

        if ($inbox === null) {
            $this->error("Could not open INBOX for {$account->email_address}.");

            return self::FAILURE;
        }

        // IDLE blocks until the server pushes a notification (new message,
        // flag change, expunge). We dispatch a sync job and immediately
        // re-enter IDLE — the sync handles deduplication, so triggering it
        // on any IDLE event is safe. launchd/supervisor restarts us if the
        // connection drops or the server kicks us out after ~30 min.
        //
        // Folder::idle() runs its own internal while(true) loop and only
        // ever returns via an exception, so re-checking the account once at
        // startup isn't enough — the account could be deleted mid-session.
        // We re-check on every wake and throw to break out cleanly instead
        // of dispatching a job for a watched account that's gone.
        try {
            $inbox->idle(fn () => $this->onIdleWake($account));
        } catch (AccountWatchStoppedException) {
            $this->info("Account {$account->email_address} was deleted or deactivated — exiting.");

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /** @throws AccountWatchStoppedException */
    private function onIdleWake(MailAccount $account): void
    {
        $current = MailAccount::find($account->id);

        if (! $current || ! $current->is_active) {
            throw new AccountWatchStoppedException;
        }

        $this->line('['.now()->toTimeString().'] Activity on '.$account->email_address.' — queuing sync');
        SyncMailAccountJob::dispatch($account);
    }
}
