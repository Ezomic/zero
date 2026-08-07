<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\MailAccount;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\ImapSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches bodies for messages that have never been opened, so search can see
 * them.
 *
 * Bulk sync deliberately fetches headers only, and fetchBody() runs the first
 * time a message is opened. Almost no message is ever opened, so almost no
 * body exists: on production, 113 of 4361 live messages, 2.6 percent. Since
 * emails_fts indexes body_text, searching for a phrase from inside a message
 * found nothing for the other 97 percent (ZERO-102).
 *
 * Fetching at sync time was the obvious alternative and is the wrong trade:
 * measured against a real Gmail account a body costs about 0.58s, so a folder
 * with 50 new messages would add half a minute to a sync that currently takes
 * under a second. This runs separately instead, on its own budget, and stops
 * cleanly when the budget is spent. Progress is inherently checkpointed:
 * every fetched body is one fewer row the next run selects.
 */
class BackfillBodiesCommand extends Command
{
    protected $signature = 'mail:backfill-bodies
        {--account= : Restrict to one MailAccount id}
        {--seconds=60 : Wall-clock budget for this run}
        {--limit=500 : Most messages to consider in one run}';

    protected $description = 'Fetch message bodies that were never loaded, so full-text search can match them';

    public function handle(ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): int
    {
        $deadline = now()->addSeconds(max(1, (int) $this->option('seconds')));

        $pending = Email::query()
            ->whereNull('body_text')
            ->whereNull('body_html')
            ->where('is_deleted', false)
            ->whereNotNull('uid')
            ->when($this->option('account'), fn ($query) => $query->where('mail_account_id', (int) $this->option('account')))
            ->whereHas('mailAccount', fn ($query) => $query->where('is_active', true))
            // Newest first: recent mail is what someone is most likely to
            // search for, and a large backlog may never fully drain.
            ->latest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $fetched = 0;
        $failed = 0;

        foreach ($pending as $email) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $account = $email->mailAccount;

            if (! $account) {
                continue;
            }

            try {
                $service = $account->provider === MailAccount::PROVIDER_OUTLOOK ? $graphMailSyncService : $imapSyncService;
                $service->fetchBody($email);
                $fetched++;
            } catch (\Throwable $e) {
                // A message can be gone from the server, or its uid stale
                // after an out-of-band move. One bad row must not stop the
                // run; the next one simply picks up where this left off.
                $failed++;
                Log::debug("Body backfill skipped email {$email->id}: ".$e->getMessage());
            }
        }

        $remaining = Email::query()
            ->whereNull('body_text')
            ->whereNull('body_html')
            ->where('is_deleted', false)
            ->count();

        $this->info("Fetched {$fetched} bodies, {$failed} skipped, {$remaining} still without one.");

        return self::SUCCESS;
    }
}
