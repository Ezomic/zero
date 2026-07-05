<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use Illuminate\Console\Command;

class SyncMailboxesCommand extends Command
{
    protected $signature = 'mail:sync {--account= : Sync a single MailAccount by ID}';

    protected $description = 'Dispatch IMAP sync jobs for active mail accounts';

    public function handle(): int
    {
        // Skip inactive accounts — those were deactivated by an auth failure
        // and need credentials fixed before they can run again. Accounts in
        // error status due to transient failures (timeout, network) stay active
        // and keep getting dispatched so they self-recover.
        $query = MailAccount::query()->where('is_active', true);

        if ($accountId = $this->option('account')) {
            $query->where('id', $accountId);
        }

        $accounts = $query->get();

        foreach ($accounts as $account) {
            SyncMailAccountJob::dispatch($account);
            $this->info("Queued sync for {$account->email_address}");
        }

        return self::SUCCESS;
    }
}
