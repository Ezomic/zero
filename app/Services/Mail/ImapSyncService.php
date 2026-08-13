<?php

namespace App\Services\Mail;

use App\Events\NewEmailArrived;
use App\Exceptions\SyncBudgetExceededException;
use App\Models\Contact;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\PendingMirrorAction;
use App\Support\MimeHeader;
use App\Support\SearchableBody;
use App\Support\StoredAttachmentName;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Connects to an account's IMAP server (Gmail, Outlook, or custom) and pulls
 * new messages into the local `emails` table. Safe to run repeatedly —
 * dedupes on (mail_account_id, folder, uid).
 *
 * ## Sync strategy overview
 *
 * Two complementary paths keep the local database up to date:
 *
 * ### Polling (every 5 minutes)
 * `mail:sync` is dispatched by the scheduler every 5 minutes via `withoutOverlapping()`.
 * It enqueues a `SyncMailAccountJob` for every active account, which calls
 * `ImapSyncService::sync()`. Incremental syncs only fetch UIDs above `last_uid`
 * (stored per folder in `mail_folders`), so they complete in milliseconds for
 * most runs. The first sync of a new account fetches everything and can be slow
 * for large mailboxes — that's why `SyncMailAccountJob::$timeout = 1800` (30 min).
 * A first-time full sync fetches oldest-first in batches of
 * `FULL_SYNC_CHUNK_SIZE`, checkpointing `last_uid` after every batch — if the
 * job still times out on a very large mailbox, the next attempt resumes as an
 * incremental sync from the last completed batch instead of starting over.
 *
 * ### IMAP IDLE (real-time push)
 * `mail:idle {account}` holds a persistent IDLE connection on the account's INBOX.
 * When the server pushes a notification (new message, flag change, expunge), it
 * immediately dispatches `SyncMailAccountJob` — no 5-minute wait. launchd runs one
 * idle process per account and restarts it automatically if it dies (servers drop
 * IDLE connections after ~30 min, which is the normal case). The polling path acts
 * as a guaranteed fallback for accounts without an IDLE process (e.g. when adding
 * a new account before its launchd agent is set up).
 *
 * ### Incremental vs full resync
 * `last_uid = 0` → full fetch (first sync or forced resync via Tinker).
 * `last_uid > 0` → incremental, fetches only `UID > last_uid`.
 * If `uid_validity` changes (server recreated the folder), `last_uid` is reset to 0
 * and a full resync runs automatically.
 *
 * ### Sync status lifecycle
 * `idle → syncing → idle`  (happy path)
 * `idle → syncing → error` (transient failure — `is_active` stays true, retries self-recover)
 * `idle → syncing → error` (auth failure — `is_active = false`, needs re-enable)
 */
class ImapSyncService
{
    /** Local canonical folder names, keyed by the substring we match against
     *  the remote folder name (case-insensitively). Order matters — first
     *  match wins. Anything that doesn't match becomes a custom folder,
     *  keyed by its own remote name. Includes a few confirmed non-English
     *  synonyms (Gmail's own Drafts/Trash names vary by account language)
     *  — folders in other languages just end up as their own custom folder
     *  instead of being merged into the canonical tab, which is harmless. */
    protected const FOLDER_MATCHERS = [
        'sent' => 'SENT',
        'draft' => 'DRAFTS',
        'concepten' => 'DRAFTS', // Dutch
        'trash' => 'TRASH',
        'deleted' => 'TRASH',
        'prullenbak' => 'TRASH', // Dutch
    ];

    /**
     * Gmail's aggregate/duplicate views — never sync these as their own
     * folder, since every message in them already exists elsewhere and
     * syncing them would duplicate the entire mailbox. Name matching can't
     * cover every Gmail UI language, so this is deliberately a substring
     * match (not exact) plus a handful of confirmed translations; the
     * structural check in foldersToSync() below is the real safety net for
     * languages not listed here.
     */
    protected const EXCLUDED_FOLDER_SUBSTRINGS = [
        'gmail', // catches the "[Gmail]" container folder itself
        'all mail', 'alle e-mail', // English, Dutch
        'important', 'belangrijk', // English, Dutch — 'important' also matches
        // Spanish/Italian/Portuguese "Importante" since it's a prefix of it.
        'starred',
        'tous les messages', // French "All Mail"
        'alle nachrichten', // German "All Mail"
        'tutti i messaggi', // Italian "All Mail"
        'todos los mensajes', // Spanish "All Mail"
        'todos os e-mails', // Portuguese "All Mail"
    ];

    /** A folder that isn't one of the known matchers/exclusions above but
     *  alone accounts for a large share of everything we're about to sync
     *  (Inbox + all other unmatched folders) is almost certainly an
     *  aggregate view in a language not covered above, not a genuine custom
     *  folder — catches what name-matching alone would miss. Only applies
     *  once a folder is already this large in absolute terms, so small
     *  mailboxes with one dominant label aren't wrongly excluded. */
    protected const AGGREGATE_FOLDER_MIN_ABSOLUTE_COUNT = 500;

    protected const AGGREGATE_FOLDER_SHARE_THRESHOLD = 0.5;

    /** A first-time full sync fetches a folder's messages in batches of this
     *  size (oldest-first) instead of one blocking call for everything.
     *  `last_uid` is checkpointed after every batch, so a job that times out
     *  mid-folder resumes as an incremental sync from the last completed
     *  batch on the next attempt instead of starting over from scratch.
     *  Kept small deliberately: a single batched IMAP FETCH for headers can
     *  still be very slow on some accounts (observed 6-8s/message on one
     *  production Gmail account, likely server-side throttling) — a large
     *  chunk risks the job's 30-minute timeout killing it mid-fetch before
     *  even one batch checkpoints, which loses the entire attempt's
     *  progress. */
    protected const FULL_SYNC_CHUNK_SIZE = 25;

    /** Soft wall-clock budget for a single sync run, kept comfortably below
     *  SyncMailAccountJob::$timeout (1800s). Folders are processed
     *  least-recently-synced first and each run stops cleanly once this
     *  budget is spent, so no single large or throttled folder can consume
     *  the whole job and starve the others: the next scheduled run resumes
     *  from the folders that didn't get their turn. Headroom below the hard
     *  timeout covers the one in-flight batch that may still finish after the
     *  deadline is crossed (checkpointed before we bail). */
    protected const SYNC_TIME_BUDGET_SECONDS = 1500;

    /**
     * Mirror actions that are a single IMAP flag, as [flag, add-or-remove].
     * These batch into one UID STORE per contiguous run, so adding one costs
     * nothing extra per message (ZERO-113).
     */
    protected const FLAG_ACTIONS = [
        'mark_read' => ['\Seen', '+'],
        'mark_unread' => ['\Seen', '-'],
        'star' => ['\Flagged', '+'],
        'unstar' => ['\Flagged', '-'],
    ];

    public function __construct(
        protected ImapClientFactory $clientFactory,
    ) {}

    /**
     * Bulk sync fetches headers/flags only (no body, no attachment content)
     * so a large mailbox stays fast — bodies are fetched on demand the first
     * time a message is opened, via fetchBody().
     */
    public function sync(MailAccount $account, int $maxMessagesPerFolder = 5000): void
    {
        $account->update(['sync_status' => 'syncing', 'sync_status_since' => now(), 'sync_error' => null]);

        // Scopes the contact memo to this run, so the queue worker does not
        // carry one entry per address it has ever seen (ZERO-107).
        Contact::forgetHandledThisRun();

        $capturingImapTraffic = $this->beginImapTrafficCapture($account);

        try {
            $client = $this->buildClient($account);
            $client->connect();

            $folders = $this->foldersToSync($client);

            foreach ($folders as $remotePath => $localName) {
                MailFolder::updateOrCreate(
                    ['mail_account_id' => $account->id, 'remote_path' => $remotePath],
                    ['local_name' => $localName]
                );
            }

            // Process folders least-recently-synced first and give the whole
            // run a soft time budget. Each folder is touched before we work
            // it, so even a folder whose fetch hard-times-out (killed by the
            // job timeout before it can checkpoint) still sorts to the back
            // next run. One bad folder wastes at most one run per rotation
            // instead of blocking every folder behind it forever.
            $deadline = now()->addSeconds(self::SYNC_TIME_BUDGET_SECONDS);

            $folderRecords = MailFolder::where('mail_account_id', $account->id)
                ->whereIn('remote_path', array_keys($folders))
                ->orderBy('updated_at')
                ->orderBy('id')
                ->get();

            $completed = true;

            foreach ($folderRecords as $folderRecord) {
                if (now()->greaterThanOrEqualTo($deadline)) {
                    $completed = false;
                    break;
                }

                $folder = $client->getFolder($folderRecord->remote_path);

                if (! $folder) {
                    continue;
                }

                $folderRecord->touch();

                $this->syncFolder($account, $folder, $folderRecord, $folders[$folderRecord->remote_path], $maxMessagesPerFolder, $deadline);
            }

            // Only a run that drained every folder within its budget counts as
            // fully caught up; otherwise stay in `syncing` so the scheduler's
            // next dispatch resumes the remaining folders.
            $account->update([
                'sync_status' => $completed ? 'idle' : 'syncing',
                'sync_status_since' => now(),
                'last_synced_at' => $completed ? now() : $account->last_synced_at,
            ]);
        } catch (SyncBudgetExceededException) {
            $account->update([
                'sync_status' => 'syncing',
                'sync_status_since' => now(),
            ]);
        } catch (\Throwable $e) {
            $account->update([
                'sync_status' => 'error',
                'sync_status_since' => now(),
                'sync_error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($capturingImapTraffic) {
                ob_end_flush();
            }
        }
    }

    /**
     * Begin capturing webklex's echoed IMAP protocol traffic when IMAP_DEBUG is
     * on, so it is masked into the imap log rather than leaking to stdout — or,
     * for web-context callers (fetchBody), into the HTTP response
     * body, which would expose the account's credentials. Returns whether an
     * output buffer was opened; the caller must ob_end_flush() it in a finally.
     */
    protected function beginImapTrafficCapture(MailAccount $account): bool
    {
        if (! (bool) config('imap.options.debug')) {
            return false;
        }

        ob_start($this->imapTrafficLogger($account), 4096);

        return true;
    }

    /**
     * Output-buffer handler that routes webklex's echoed IMAP protocol traffic
     * to the dedicated imap log channel with credentials masked. Returns an
     * empty string so the raw traffic never reaches stdout or the response body.
     */
    protected function imapTrafficLogger(MailAccount $account): \Closure
    {
        return function (string $buffer) use ($account): string {
            foreach (preg_split('/\r?\n/', $buffer) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }

                // Never persist credentials: mask everything after the LOGIN
                // user argument and after an AUTHENTICATE mechanism (covers the
                // app password and any OAuth token).
                $line = (string) preg_replace('/(\bLOGIN\s+\S+\s+).*/i', '$1***', $line);
                $line = (string) preg_replace('/(\bAUTHENTICATE\s+\S+\s*).*/i', '$1***', $line);

                Log::channel('imap')->debug("account {$account->id}: ".$line);
            }

            return '';
        };
    }

    /**
     * Fetches the full body (and attachments) for a single message the first
     * time it's opened. Bulk sync deliberately skips this for speed.
     */
    public function fetchBody(Email $email): void
    {
        $account = $email->requireMailAccount();
        $capturingImapTraffic = $this->beginImapTrafficCapture($account);

        try {
            $remotePath = $email->remote_folder_path ?: $email->folder;

            $client = $this->buildClient($account);
            $client->connect();

            $folder = $client->getFolder($remotePath);

            if (! $folder) {
                throw new RuntimeException("Remote folder not found: {$remotePath}");
            }

            $message = $folder->messages()->fetchBody(true)->getMessageByUid((int) $email->uid);

            $html = $message->getHTMLBody() ?: null;

            $email->update([
                'body_html' => $html,
                // Falls back to a stripped copy of the HTML when the message
                // carries no text part, since the FTS triggers only ever read
                // body_text and an HTML-only message was otherwise absent
                // from search entirely (ZERO-102).
                'body_text' => SearchableBody::forStorage($message->getTextBody() ?: null, $html),
                'has_attachments' => $message->hasAttachments(),
            ]);

            if ($message->hasAttachments() && $email->attachments()->count() === 0) {
                foreach ($message->getAttachments() as $attachment) {
                    if (! $attachment instanceof Attachment) {
                        continue;
                    }

                    // One unstorable attachment must not cost the message its
                    // others: the body is already saved by this point, so a
                    // throw here would leave the message with no attachment
                    // rows and fetchBody() would never run again (ZERO-106).
                    try {
                        $path = "email-attachments/{$account->id}/{$email->id}/".StoredAttachmentName::for($attachment->getName());
                        Storage::disk('local')->put($path, $attachment->getContent());

                        EmailAttachment::create([
                            'email_id' => $email->id,
                            'filename' => $attachment->getName(),
                            'mime_type' => $attachment->getMimeType(),
                            'size_bytes' => $attachment->getSize(),
                            'storage_path' => $path,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning("Could not store attachment on email {$email->id}: ".$e->getMessage());
                    }
                }
            }
        } finally {
            if ($capturingImapTraffic) {
                ob_end_flush();
            }
        }
    }

    /**
     * Applies every outstanding action for an account in as few commands as
     * possible: one connection for the account, one folder select per folder,
     * and one UID-set command per (folder, action) group instead of a fresh
     * IMAP session per message (ZERO-78).
     *
     * @param  Collection<int, PendingMirrorAction>  $actions
     */
    public function applyPendingActions(MailAccount $account, Collection $actions): void
    {
        $capturingImapTraffic = $this->beginImapTrafficCapture($account);

        try {
            $client = $this->buildClient($account);
            $client->connect();

            foreach ($actions->groupBy('remote_folder_path') as $remotePath => $folderActions) {
                $this->applyFolderActions($account, $client, (string) $remotePath, $folderActions);
            }
        } finally {
            if ($capturingImapTraffic) {
                ob_end_flush();
            }
        }
    }

    /**
     * Flags are applied before deletes and moves so a "read it, then bin it"
     * sequence still lands in that order once the two have been grouped apart.
     *
     * @param  Collection<int, PendingMirrorAction>  $actions
     */
    protected function applyFolderActions(MailAccount $account, Client $client, string $remotePath, Collection $actions): void
    {
        try {
            $client->getConnection()->selectFolder($remotePath);
        } catch (\Throwable $e) {
            $actions->each(fn (PendingMirrorAction $action) => $action->recordFailure($e->getMessage()));

            return;
        }

        $byAction = $actions->groupBy('action');

        foreach (self::FLAG_ACTIONS as $name => [$flag, $mode]) {
            $group = $byAction->get($name);

            if ($group instanceof Collection && $group->isNotEmpty()) {
                $this->storeFlagForGroup($client, $group, $flag, $mode);
            }
        }

        $deletes = $byAction->get('delete');

        if ($deletes instanceof Collection && $deletes->isNotEmpty()) {
            $this->deleteGroup($account, $client, $deletes);
        }

        foreach ($actions->filter(fn (PendingMirrorAction $action) => $action->isMove()) as $action) {
            $this->applyPendingMove($account, $client, $remotePath, $action);
        }
    }

    /**
     * One UID STORE per contiguous run. A bulk triage produces mostly
     * consecutive UIDs, so thousands of individual commands collapse into a
     * handful — and each Gmail round-trip was costing ~1.8s.
     *
     * @param  Collection<int, PendingMirrorAction>  $actions
     */
    protected function storeFlagForGroup(Client $client, Collection $actions, string $flag, string $mode): void
    {
        $connection = $client->getConnection();

        foreach ($this->uidRuns($actions) as [$from, $to, $runActions]) {
            $succeeded = false;

            try {
                $succeeded = $connection->store([$flag], $from, $from === $to ? null : $to, $mode)->boolean();
            } catch (\Throwable $e) {
                $this->retryRunIndividually($runActions, fn (int $uid) => $connection->store([$flag], $uid, null, $mode)->boolean(), $e->getMessage());

                continue;
            }

            if ($succeeded) {
                PendingMirrorAction::whereIn('id', $runActions->pluck('id')->all())->delete();

                continue;
            }

            // A range that comes back NO tells us nothing about which UID in it
            // was the problem, so fall back to one command per message and keep
            // the rest of the batch moving.
            $this->retryRunIndividually($runActions, fn (int $uid) => $connection->store([$flag], $uid, null, $mode)->boolean(), 'IMAP store failed for range');
        }
    }

    /**
     * Deletes go out as a single UID MOVE — that command takes an arbitrary
     * set, so contiguity doesn't matter — followed by one expunge for the
     * whole folder rather than one per message.
     *
     * @param  Collection<int, PendingMirrorAction>  $actions
     */
    protected function deleteGroup(MailAccount $account, Client $client, Collection $actions): void
    {
        $trashPath = $this->trashPathFor($account, $client);
        $connection = $client->getConnection();
        $uids = $this->uidsFor($actions);

        if ($trashPath === null) {
            foreach ($this->uidRuns($actions) as [$from, $to, $runActions]) {
                try {
                    if ($connection->store(['\Deleted'], $from, $from === $to ? null : $to, '+')->boolean()) {
                        PendingMirrorAction::whereIn('id', $runActions->pluck('id')->all())->delete();

                        continue;
                    }
                } catch (\Throwable $e) {
                    $runActions->each(fn (PendingMirrorAction $action) => $action->recordFailure($e->getMessage()));

                    continue;
                }

                $runActions->each(fn (PendingMirrorAction $action) => $action->recordFailure('IMAP delete failed for range'));
            }

            $connection->expunge();

            return;
        }

        try {
            if ($connection->moveManyMessages(array_map(strval(...), array_keys($uids)), $trashPath)->boolean()) {
                $connection->expunge();
                PendingMirrorAction::whereIn('id', $actions->pluck('id')->all())->delete();

                return;
            }
        } catch (\Throwable $e) {
            $this->retryRunIndividually($actions, fn (int $uid) => $connection->moveMessage($trashPath, $uid)->boolean(), $e->getMessage());
            $connection->expunge();

            return;
        }

        $this->retryRunIndividually($actions, fn (int $uid) => $connection->moveMessage($trashPath, $uid)->boolean(), 'IMAP move to trash failed for set');
        $connection->expunge();
    }

    /**
     * Moves still go one at a time: only the high-level API reports back the
     * UID the message was given in its destination, and without that every
     * later action would address the wrong message. They do at least share the
     * connection and folder select with the rest of the batch.
     */
    protected function applyPendingMove(MailAccount $account, Client $client, string $remotePath, PendingMirrorAction $action): void
    {
        $email = $action->email;

        if (! $email) {
            $action->delete();

            return;
        }

        try {
            $folder = $client->getFolder($remotePath);

            if (! $folder) {
                $action->recordFailure("Unknown source folder: {$remotePath}");

                return;
            }

            $this->applyMove($email, $account, $folder->messages()->getMessageByUid((int) $action->uid), $action->moveTarget());
            $action->delete();
        } catch (\Throwable $e) {
            $action->recordFailure($e->getMessage());
        }
    }

    /**
     * @param  Collection<int, PendingMirrorAction>  $actions
     * @param  callable(int): bool  $apply
     */
    protected function retryRunIndividually(Collection $actions, callable $apply, string $rangeError): void
    {
        foreach ($this->uidsFor($actions) as $uid => $uidActions) {
            try {
                if ($apply($uid)) {
                    PendingMirrorAction::whereIn('id', $uidActions->pluck('id')->all())->delete();

                    continue;
                }

                $uidActions->each(fn (PendingMirrorAction $action) => $action->recordFailure($rangeError));
            } catch (\Throwable $e) {
                $uidActions->each(fn (PendingMirrorAction $action) => $action->recordFailure($e->getMessage()));
            }
        }
    }

    /**
     * Collapses the group's UIDs into ascending contiguous runs, each carrying
     * the actions it covers.
     *
     * @param  Collection<int, PendingMirrorAction>  $actions
     * @return array<int, array{0: int, 1: int, 2: Collection<int, PendingMirrorAction>}>
     */
    protected function uidRuns(Collection $actions): array
    {
        $byUid = $this->uidsFor($actions);
        $runs = [];

        foreach ($byUid as $uid => $uidActions) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][1] === $uid - 1) {
                $runs[$last][1] = $uid;
                $runs[$last][2] = $runs[$last][2]->merge($uidActions);

                continue;
            }

            $runs[] = [$uid, $uid, $uidActions];
        }

        return $runs;
    }

    /**
     * Actions keyed by UID, ascending. Duplicates are real — the same message
     * can be marked read twice before either reaches the server — so each key
     * holds every action for that UID.
     *
     * @param  Collection<int, PendingMirrorAction>  $actions
     * @return array<int, Collection<int, PendingMirrorAction>>
     */
    protected function uidsFor(Collection $actions): array
    {
        $byUid = [];

        foreach ($actions as $action) {
            $byUid[(int) $action->uid][] = $action;
        }

        ksort($byUid);

        return array_map(fn (array $group) => new Collection($group), $byUid);
    }

    protected function applyMove(Email $email, MailAccount $account, Message $message, string $targetLocalName): void
    {
        $targetPath = MailFolder::where('mail_account_id', $account->id)
            ->where('local_name', $targetLocalName)
            ->value('remote_path');

        if (! $targetPath) {
            throw new RuntimeException("Unknown target folder: {$targetLocalName}");
        }

        // IMAP UIDs are per-folder, so the moved message gets a new UID in
        // its destination — update our record or later actions (mark
        // read/delete/etc.) would target the wrong message.
        $moved = $message->move(is_string($targetPath) ? $targetPath : '', expunge: true);

        if ($moved) {
            $email->update([
                'uid' => (string) $moved->getUid(),
                'remote_folder_path' => $targetPath,
            ]);
        }
    }

    /**
     * Map remote folder paths to a local folder name. Sent/Drafts/Trash are
     * matched heuristically since providers name them differently (e.g.
     * Gmail's "[Gmail]/Sent Mail" vs a generic "Sent"); anything else becomes
     * a custom folder keyed by its own name, except Gmail's aggregate views
     * (All Mail/Important/Starred) which would just duplicate every message.
     */
    /** @return array<string, string> */
    protected function foldersToSync(Client $client): array
    {
        $result = ['INBOX' => 'INBOX'];
        $candidates = []; // path => ['folder' => Folder, 'localName' => ?string]

        foreach ($client->getFolders(false) as $folder) {
            /** @var Folder $folder */
            if (strcasecmp($folder->name, 'INBOX') === 0) {
                continue;
            }

            $lower = strtolower($folder->name);

            if ($this->looksLikeAggregateFolderName($lower)) {
                continue;
            }

            $localName = null;

            foreach (self::FOLDER_MATCHERS as $needle => $canonical) {
                if (str_contains($lower, $needle)) {
                    $localName = $canonical;
                    break;
                }
            }

            $candidates[$folder->full_name ?? $folder->path] = [
                'folder' => $folder,
                'localName' => $localName,
            ];
        }

        // Structural fallback for aggregate/duplicate folders in a Gmail UI
        // language not covered by looksLikeAggregateFolderName(): a single
        // "custom" folder that alone accounts for most of the mail we're
        // about to sync (Inbox + all other unmatched folders combined) is
        // almost certainly a duplicate view like "All Mail", not genuine
        // content — real custom folders (labels) are typically a small
        // subset, not a majority.
        $inboxCount = $this->folderMessageCount($client->getFolder('INBOX')) ?? 0;

        $unmatchedCounts = [];
        foreach ($candidates as $path => $info) {
            if ($info['localName'] === null) {
                $unmatchedCounts[$path] = $this->folderMessageCount($info['folder']) ?? 0;
            }
        }

        $totalConsidered = $inboxCount + array_sum($unmatchedCounts);

        foreach ($candidates as $path => $info) {
            if ($info['localName'] === null && $totalConsidered > 0) {
                $count = $unmatchedCounts[$path] ?? 0;

                if ($count >= self::AGGREGATE_FOLDER_MIN_ABSOLUTE_COUNT && $count > $totalConsidered * self::AGGREGATE_FOLDER_SHARE_THRESHOLD) {
                    continue;
                }
            }

            // Custom folders use their full remote path as the local name,
            // not just the leaf name — a label can share a leaf name with a
            // completely different, differently-nested label (e.g. a
            // top-level "Games" vs a separate "Inbox/Games"), and collapsing
            // those would make "move to folder" ambiguous.
            $result[$path] = $info['localName'] ?? $path;
        }

        return $result;
    }

    protected function looksLikeAggregateFolderName(string $lower): bool
    {
        foreach (self::EXCLUDED_FOLDER_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function folderMessageCount(?Folder $folder): ?int
    {
        if (! $folder) {
            return null;
        }

        try {
            $examined = $folder->examine();

            return is_numeric($examined['exists'] ?? null) ? (int) $examined['exists'] : 0;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Prefer the trash folder we already recorded during sync; enumerating the
     * folder tree to guess it cost ~3s on every single action.
     */
    protected function trashPathFor(MailAccount $account, Client $client): ?string
    {
        $recorded = MailFolder::where('mail_account_id', $account->id)
            ->where('local_name', 'TRASH')
            ->value('remote_path');

        if (is_string($recorded) && $recorded !== '') {
            return $recorded;
        }

        return $this->guessTrashPath($client);
    }

    protected function guessTrashPath(Client $client): ?string
    {
        foreach ($client->getFolders(false) as $folder) {
            /** @var Folder $folder */
            if (str_contains(strtolower($folder->name), 'trash') || str_contains(strtolower($folder->name), 'deleted')) {
                return $folder->full_name ?? $folder->path;
            }
        }

        return null;
    }

    protected function syncFolder(MailAccount $account, Folder $folder, MailFolder $folderRecord, string $folderName, int $limit, ?CarbonInterface $deadline = null): void
    {
        // Record UIDVALIDITY on every run, including first-time full syncs.
        // IMAP UIDs are only stable while UIDVALIDITY is unchanged, so a
        // genuine change means the server recreated the folder and our stored
        // UIDs are meaningless — wipe the cursor and resync in full.
        //
        // A *stored* 0 is not a change: it just means we've never recorded a
        // UIDVALIDITY for this folder yet (a first-time full sync, or a folder
        // synced before this value was persisted). Adopt the server's value
        // without discarding progress. Treating 0 as a change is what caused
        // every folder to reset on its first incremental run and re-fetch the
        // whole mailbox forever (see ZERO-50).
        $examined = $folder->examine();
        $serverUidValidity = is_numeric($examined['uidvalidity'] ?? null) ? (int) $examined['uidvalidity'] : 0;
        $storedUidValidity = $folderRecord->uid_validity ?? 0;

        if ($storedUidValidity > 0 && $serverUidValidity > 0 && $serverUidValidity !== $storedUidValidity) {
            $folderRecord->update(['last_uid' => 0, 'uid_validity' => $serverUidValidity]);
        } elseif ($serverUidValidity > 0 && $serverUidValidity !== $storedUidValidity) {
            $folderRecord->update(['uid_validity' => $serverUidValidity]);
        }

        $incremental = $folderRecord->last_uid > 0;
        $highestUid = $folderRecord->last_uid;

        if ($incremental) {
            // Fetch new messages (uid > last_uid) oldest-first, in
            // checkpointed batches. A folder that has fallen far behind can
            // have thousands of messages above its cursor, and on a throttled
            // account each header FETCH costs several seconds — a single
            // unbounded fetch would blow the job timeout and lose the whole
            // attempt (see ZERO-53). Retrieving the UID list is cheap; only
            // the per-message FETCH is slow, so page it and checkpoint
            // last_uid after every batch, stopping cleanly at the run budget.
            // Read the folder's UID list via getUid() and filter to
            // uid > last_uid, rather than whereUid('n:*'): webklex quotes a
            // range value, so whereUid sends `UID SEARCH UID "n:*"` which Gmail
            // rejects with "BAD Could not parse command" (see ZERO-61). getUid()
            // returns the currently-examined folder's UIDs and is safe here
            // because options.uid_cache is false (otherwise it could hand back a
            // previous folder's cached list, since EXAMINE does not clear the
            // cache). syncFolder examined $folder above, so it is the selected
            // mailbox.
            $serverUids = $this->numericUids($folder->getClient()->getConnection()->getUid()->validatedData());

            // Done before fetching anything: the UID list is already in hand
            // and this costs one more command, whereas the fetch below is the
            // part that can run out of budget. Cheap and complete first.
            $this->reconcileRemoteState($account, $folder, $folderName, $serverUids);

            $newUids = collect($serverUids)
                ->filter(fn (int $uid): bool => $uid > $folderRecord->last_uid)
                ->sort()
                ->values();

            foreach ($newUids->chunk(self::FULL_SYNC_CHUNK_SIZE) as $batch) {
                $messages = $folder->query()->setFetchBody(false)->curate_messages($batch->values());

                foreach ($messages as $message) {
                    if (! $message instanceof Message) {
                        continue;
                    }

                    $highestUid = max($highestUid, $this->storeMessage($account, $folder, $folderName, $message, broadcastNew: true));
                }

                if ($highestUid > $folderRecord->last_uid) {
                    $folderRecord->update(['last_uid' => $highestUid]);
                }

                if ($deadline && now()->greaterThanOrEqualTo($deadline)) {
                    throw new SyncBudgetExceededException;
                }
            }
        } else {
            // First-time full sync: a large mailbox can have thousands of
            // messages, and fetching them all in one blocking call risks
            // never finishing within the job's timeout. Fetch oldest-first in
            // small batches instead, checkpointing `last_uid` after each one.
            // If the job times out partway through, the next attempt resumes
            // as an ordinary incremental sync from the last completed batch —
            // no progress is lost, and nothing is skipped.
            //
            // Uses the library's own chunked() helper rather than a manual
            // limit($page)->get() loop: chunked() issues one IMAP SEARCH up
            // front and reuses it for every page, whereas a manual loop
            // re-runs the full SEARCH on every single chunk — redundant, and
            // confirmed in production to be what was actually stalling chunk
            // 2+ out to the job's 30-minute timeout on a real mailbox.
            //
            // all() sets the "ALL" search criteria — without it the query has
            // no criteria at all and the server rejects it with "Missing
            // search parameters" (see THI-292).
            $folder->messages()->all()->setFetchBody(false)->fetchOrderAsc()
                ->chunked(function (iterable $batch) use ($account, $folder, $folderName, $folderRecord, $deadline, &$highestUid) {
                    foreach ($batch as $message) {
                        if (! $message instanceof Message) {
                            continue;
                        }

                        $highestUid = max($highestUid, $this->storeMessage($account, $folder, $folderName, $message, broadcastNew: false));
                    }

                    if ($highestUid > $folderRecord->last_uid) {
                        $folderRecord->update(['last_uid' => $highestUid]);
                    }

                    // Stop cleanly at the run's time budget. Progress for this
                    // batch is already checkpointed above, so the next run
                    // resumes this folder as an ordinary incremental sync.
                    if ($deadline && now()->greaterThanOrEqualTo($deadline)) {
                        throw new SyncBudgetExceededException;
                    }
                }, self::FULL_SYNC_CHUNK_SIZE);
        }

        if ($highestUid > $folderRecord->last_uid) {
            $folderRecord->update(['last_uid' => $highestUid]);
        }
    }

    /**
     * Brings already-stored messages back in line with the server.
     *
     * Incremental sync only ever fetches UIDs above the cursor, so nothing it
     * fetches says anything about the messages already stored: read something
     * on your phone and this app kept showing it unread indefinitely, delete
     * it elsewhere and it stayed in the list forever (ZERO-90). Outlook was
     * never affected, Graph's delta reports changes and removals itself.
     *
     * Both halves are one IMAP command each regardless of folder size — the
     * UID list is already fetched, and UNSEEN is a single SEARCH — so this
     * cannot grow into the sync's time budget the way a per-message fetch
     * would.
     *
     * @param  list<int>  $serverUids  every UID currently in the folder
     */
    protected function reconcileRemoteState(MailAccount $account, Folder $folder, string $folderName, array $serverUids): void
    {
        // An empty list is ambiguous: a genuinely emptied folder looks exactly
        // like a UID fetch that came back with nothing, and acting on the
        // second would wipe the folder locally. Being a run late costs far
        // less than being wrong, so leave it for a run that sees something.
        if ($serverUids === []) {
            return;
        }

        $local = $this->reconcilableEmails($account, $folderName);

        if ($local === []) {
            return;
        }

        $present = array_flip($serverUids);
        $gone = [];
        $stillHere = [];

        foreach ($local as $row) {
            isset($present[$row['uid']]) ? $stillHere[] = $row : $gone[] = $row['id'];
        }

        $this->markDeleted($gone);
        $this->reconcileReadFlags($folder, $stillHere);
    }

    /**
     * The folder's live rows, excluding any with an action of our own still
     * waiting to reach the server: until a drain has pushed a "mark read", the
     * server legitimately still reports it unseen, and reconciling against
     * that would undo what the user just did.
     *
     * @return list<array{id: int, uid: int, is_read: bool, is_starred: bool}>
     */
    protected function reconcilableEmails(MailAccount $account, string $folderName): array
    {
        $rows = Email::query()
            ->where('mail_account_id', $account->id)
            ->where('folder', $folderName)
            ->where('is_deleted', false)
            ->whereNotNull('uid')
            ->whereNotExists(function (QueryBuilder $query) use ($account): void {
                $query->select(DB::raw('1'))
                    ->from('pending_mirror_actions')
                    ->where('pending_mirror_actions.mail_account_id', $account->id)
                    ->whereColumn('pending_mirror_actions.email_id', 'emails.id');
            })
            ->get(['id', 'uid', 'is_read', 'is_starred']);

        $reconcilable = [];

        foreach ($rows as $email) {
            $reconcilable[] = [
                'id' => (int) $email->id,
                'uid' => (int) $email->uid,
                'is_read' => (bool) $email->is_read,
                'is_starred' => (bool) $email->is_starred,
            ];
        }

        return $reconcilable;
    }

    /**
     * @param  Collection<int, array{id: int, uid: int, is_read: bool}>  $emails
     */
    /** @param list<array{id: int, uid: int, is_read: bool, is_starred: bool}> $emails */
    protected function reconcileReadFlags(Folder $folder, array $emails): void
    {
        if ($emails === []) {
            return;
        }

        // UNSEEN reports the messages *without* \Seen, so a hit means unread.
        $this->reconcileFlag($folder, $emails, ['UNSEEN'], 'is_read', matchMeans: false);

        // FLAGGED reports the messages *with* \Flagged, so a hit means starred.
        $this->reconcileFlag($folder, $emails, ['FLAGGED'], 'is_starred', matchMeans: true);
    }

    /**
     * Brings one boolean column in line with one IMAP search.
     *
     * Each search is a single command whatever the folder holds, so this stays
     * flat in cost, but it is still a round trip: adding a flag here is adding
     * a command to every folder on every sync (ZERO-113).
     *
     * $matchMeans is what appearing in the result set implies about the
     * column, which differs per search: UNSEEN matches the unread ones,
     * FLAGGED matches the starred ones.
     *
     * @param  list<array{id: int, uid: int, is_read: bool, is_starred: bool}>  $emails
     * @param  list<string>  $criteria
     */
    protected function reconcileFlag(Folder $folder, array $emails, array $criteria, string $column, bool $matchMeans): void
    {
        try {
            $matched = array_flip(
                $this->numericUids($folder->getClient()->getConnection()->search($criteria)->validatedData())
            );
        } catch (\Throwable $e) {
            // Not worth failing the folder over: the messages themselves are
            // synced, only their flags are stale for another run.
            Log::warning(implode(' ', $criteria)." search failed for {$folder->full_name}: ".$e->getMessage());

            return;
        }

        $shouldBeTrue = [];
        $shouldBeFalse = [];

        foreach ($emails as $row) {
            $server = isset($matched[$row['uid']]) ? $matchMeans : ! $matchMeans;

            if ($server === $row[$column]) {
                continue;
            }

            $server ? $shouldBeTrue[] = $row['id'] : $shouldBeFalse[] = $row['id'];
        }

        $this->updateInChunks($shouldBeTrue, [$column => true]);
        $this->updateInChunks($shouldBeFalse, [$column => false]);
    }

    /**
     * webklex hands back whatever the server said, so narrow it before it is
     * used as a UID set.
     *
     * @return list<int>
     */
    protected function numericUids(mixed $raw): array
    {
        $uids = [];

        foreach (is_array($raw) ? $raw : [] as $uid) {
            if (is_numeric($uid) && (int) $uid > 0) {
                $uids[] = (int) $uid;
            }
        }

        return $uids;
    }

    /** @param list<int> $ids */
    protected function markDeleted(array $ids): void
    {
        $this->updateInChunks($ids, ['is_deleted' => true]);
    }

    /**
     * Chunked because the id list is bounded only by the size of the folder,
     * and SQLite will not bind more than ~32k parameters in one statement.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     */
    protected function updateInChunks(array $ids, array $attributes): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            Email::whereIn('id', $chunk)->update($attributes);
        }
    }

    /**
     * Inserts a message if it's new (or reconciles its read flag if we
     * already have it) and returns its numeric UID. $broadcastNew controls
     * whether a genuinely new unread INBOX message fires the real-time
     * notification — false during a first-time full sync, since that would
     * flood the UI with years of old mail.
     */
    protected function storeMessage(MailAccount $account, Folder $folder, string $folderName, Message $message, bool $broadcastNew): int
    {
        $uid = (string) $message->getUid();
        $numericUid = (int) $uid;

        $existing = Email::where('mail_account_id', $account->id)
            ->where('folder', $folderName)
            ->where('uid', $uid)
            ->first();

        if ($existing) {
            // Reconcile read flag — catches messages read on another device
            // since last sync.
            $serverIsRead = $message->getFlags()->has('Seen');
            $serverIsStarred = $message->getFlags()->has('Flagged');

            if ($existing->is_read !== $serverIsRead || $existing->is_starred !== $serverIsStarred) {
                $existing->update(['is_read' => $serverIsRead, 'is_starred' => $serverIsStarred]);
            }

            return $numericUid;
        }

        $messageId = $message->getMessageId()?->toString() ?: null;

        // Reuse the ULID of the same logical message already stored in another
        // folder, so a moved message keeps one durable identity across folders
        // instead of every folder copy getting its own.
        $ulid = $messageId
            ? Email::where('mail_account_id', $account->id)
                ->where('message_id', $messageId)
                ->value('ulid')
            : null;

        [$inReplyTo, $references] = $this->threadingHeaders($message);
        $threadId = $references[0] ?? $inReplyTo ?? $messageId ?: "standalone:{$account->id}:{$folderName}:{$uid}";

        $sender = $message->getFrom()[0] ?? null;
        $fromAddress = is_object($sender) && isset($sender->mail) && is_string($sender->mail) ? $sender->mail : null;
        $senderName = is_object($sender) && isset($sender->personal) && is_string($sender->personal) ? $sender->personal : null;
        $fromName = MimeHeader::decode($senderName);
        $to = $message->getTo()?->toArray();
        $cc = $message->getCc()?->toArray();
        $toAddresses = $this->addressesToArray($to);
        $ccAddresses = $this->addressesToArray($cc);

        $subject = MimeHeader::decode($message->getSubject()->toString()) ?: '(no subject)';
        $sentAt = $this->messageDate($message);
        $isRead = $message->getFlags()->has('Seen');
        $isStarred = $message->getFlags()->has('Flagged');

        $email = Email::create([
            'mail_account_id' => $account->id,
            'ulid' => $ulid,
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'in_reply_to' => $inReplyTo,
            'references_header' => $references ? implode(' ', $references) : null,
            'folder' => $folderName,
            'remote_folder_path' => $folder->full_name ?? $folder->path,
            'uid' => $uid,
            'subject' => $subject,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'to_addresses' => $toAddresses,
            'cc_addresses' => $ccAddresses,
            'body_html' => null,
            'body_text' => null,
            'is_read' => $isRead,
            'is_starred' => $isStarred,
            'has_attachments' => false,
            'sent_at' => $sentAt,
        ]);

        if ($folderName === 'INBOX' && ! $isRead && $broadcastNew) {
            broadcast(new NewEmailArrived(
                userId: $account->user_id,
                emailId: $email->id,
                folder: $folderName,
                fromAddress: $fromAddress ?? '',
                fromName: $fromName,
                subject: $subject,
                // The toast only needs something to show, so a message that
                // stored no sent_at falls back to its arrival time rather
                // than taking down the account's whole sync run (ZERO-92).
                sentAt: $email->sent_at?->toISOString() ?? now()->toISOString() ?? '',
            ));

            if ($this->macOsNotificationsEnabled()) {
                $this->notifyMacOs($fromName ?? $fromAddress ?? 'New message', $subject);
            }
        }

        $this->recordContacts($account, $fromAddress, $fromName, $toAddresses, $ccAddresses);

        return $numericUid;
    }

    /**
     * webklex types getDate() as always returning an Attribute, but
     * Message::get() falls through to the header bag, which hands back null
     * for a message carrying no Date header at all — and Attribute::toDate()
     * throws on one it cannot parse. Neither is exceptional enough to fail an
     * account's whole sync run, so both land as a null sent_at (ZERO-92).
     */
    protected function messageDate(Message $message): ?CarbonInterface
    {
        try {
            $date = $message->getDate()?->toDate();
        } catch (\Throwable) {
            return null;
        }

        return $date;
    }

    /**
     * @return array{0: ?string, 1: string[]} [in_reply_to, references]
     */
    protected function threadingHeaders(Message $message): array
    {
        try {
            $inReplyTo = $message->getInReplyTo()?->toString() ?: null;
        } catch (\Throwable) {
            $inReplyTo = null;
        }

        try {
            $rawReferences = $message->getReferences()?->toArray() ?? [];
            $references = array_values(array_filter(
                array_map(fn (mixed $r): string => is_string($r) ? $r : '', $rawReferences),
            ));
        } catch (\Throwable) {
            $references = [];
        }

        return [$inReplyTo, $references];
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     */
    protected function recordContacts(MailAccount $account, ?string $fromAddress, ?string $fromName, array $to, array $cc): void
    {
        if ($fromAddress && strcasecmp($fromAddress, $account->email_address) !== 0) {
            Contact::remember($account->user_id, $fromAddress, $fromName);
        }

        foreach ([...$to, ...$cc] as $formatted) {
            if (preg_match('/<([^>]+)>/', $formatted, $m)) {
                $email = $m[1];
                $name = trim(str_replace("<{$email}>", '', $formatted)) ?: null;
            } else {
                $email = trim($formatted);
                $name = null;
            }

            if ($email && strcasecmp($email, $account->email_address) !== 0) {
                Contact::remember($account->user_id, $email, $name);
            }
        }
    }

    /**
     * @param  array<int, mixed>|null  $addresses
     * @return array<int, string>
     */
    /**
     * @param  array<mixed>|null  $addresses
     * @return array<int, string>
     */
    protected function addressesToArray(?array $addresses): array
    {
        if (! $addresses) {
            return [];
        }

        return collect($addresses)
            ->map(function (mixed $a): string {
                $personal = is_object($a) && isset($a->personal) && is_string($a->personal) ? $a->personal : '';
                $mail = is_object($a) && isset($a->mail) && is_string($a->mail) ? $a->mail : '';

                return trim(($personal !== '' ? $personal.' ' : ''))."<{$mail}>";
            })
            ->values()
            ->all();
    }

    /**
     * The flag alone is not enough to gate this. It defaults to on and
     * production's .env does not set it, so every new message on the Linux
     * droplet forked a shell to run an osascript that does not exist there,
     * in the middle of the sync path (ZERO-108). The platform check is the
     * real precondition; the flag is for turning it off on a Mac.
     */
    protected function macOsNotificationsEnabled(): bool
    {
        return $this->osFamily() === 'Darwin' && (bool) config('features.macos_notifications');
    }

    /** Seam so the platform branch stays testable from either OS. */
    protected function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    protected function notifyMacOs(string $from, string $subject): void
    {
        $from = str_replace('"', '\\"', $from);
        $subject = str_replace('"', '\\"', $subject);
        $script = "display notification \"{$subject}\" with title \"New mail\" subtitle \"{$from}\" sound name \"Ping\"";
        exec('osascript -e '.escapeshellarg($script).' > /dev/null 2>&1 &');
    }

    protected function buildClient(MailAccount $account): Client
    {
        return $this->clientFactory->make($account);
    }
}
