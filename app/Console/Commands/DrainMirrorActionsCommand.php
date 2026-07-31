<?php

namespace App\Console\Commands;

use App\Jobs\DrainMirrorActionsJob;
use App\Models\MailAccount;
use App\Models\PendingMirrorAction;
use Illuminate\Console\Command;

class DrainMirrorActionsCommand extends Command
{
    protected $signature = 'mail:drain-mirrors {--account= : Drain a single MailAccount by ID}';

    protected $description = 'Dispatch a drain for every account with outstanding mirror-backs';

    /**
     * A safety net rather than the main path — actions are normally drained by
     * the job queued alongside them. This catches what that misses: a drain
     * dropped as an overlapping duplicate, one killed mid-run, or rows left
     * behind by a failure that has since resolved.
     */
    public function handle(): int
    {
        $accountIds = PendingMirrorAction::query()
            ->pending()
            ->when($this->option('account'), fn ($query, $id) => $query->where('mail_account_id', $id))
            ->distinct()
            ->pluck('mail_account_id');

        $accounts = MailAccount::query()
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->get();

        foreach ($accounts as $account) {
            DrainMirrorActionsJob::dispatch($account);
            $this->info("Queued mirror drain for {$account->email_address}");
        }

        return self::SUCCESS;
    }
}
