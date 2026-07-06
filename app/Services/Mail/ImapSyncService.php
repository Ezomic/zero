<?php

namespace App\Services\Mail;

use App\Events\NewEmailArrived;
use App\Models\Contact;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\MailFolder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
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

    public function __construct(
        protected OAuthTokenRefresher $tokenRefresher,
    ) {}

    /**
     * Bulk sync fetches headers/flags only (no body, no attachment content)
     * so a large mailbox stays fast — bodies are fetched on demand the first
     * time a message is opened, via fetchBody().
     */
    public function sync(MailAccount $account, int $maxMessagesPerFolder = 5000): void
    {
        $account->update(['sync_status' => 'syncing', 'sync_status_since' => now(), 'sync_error' => null]);

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

            foreach ($folders as $remotePath => $localName) {
                $folder = $client->getFolder($remotePath);

                if (! $folder) {
                    continue;
                }

                $folderRecord = MailFolder::where('mail_account_id', $account->id)
                    ->where('remote_path', $remotePath)
                    ->first();

                $this->syncFolder($account, $folder, $folderRecord, $localName, $maxMessagesPerFolder);
            }

            $account->update([
                'sync_status' => 'idle',
                'sync_status_since' => now(),
                'last_synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $account->update([
                'sync_status' => 'error',
                'sync_status_since' => now(),
                'sync_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fetches the full body (and attachments) for a single message the first
     * time it's opened. Bulk sync deliberately skips this for speed.
     */
    public function fetchBody(Email $email): void
    {
        $account = $email->mailAccount;
        $remotePath = $email->remote_folder_path ?: $email->folder;

        $client = $this->buildClient($account);
        $client->connect();

        $folder = $client->getFolder($remotePath);

        if (! $folder) {
            throw new RuntimeException("Remote folder not found: {$remotePath}");
        }

        $message = $folder->messages()->fetchBody(true)->getMessageByUid((int) $email->uid);

        $email->update([
            'body_html' => $message->getHTMLBody() ?: null,
            'body_text' => $message->getTextBody() ?: null,
            'has_attachments' => $message->hasAttachments(),
        ]);

        if ($message->hasAttachments() && $email->attachments()->count() === 0) {
            foreach ($message->getAttachments() as $attachment) {
                $path = "email-attachments/{$account->id}/{$email->id}/".$attachment->getName();
                Storage::disk('local')->put($path, $attachment->getContent());

                EmailAttachment::create([
                    'email_id' => $email->id,
                    'filename' => $attachment->getName(),
                    'mime_type' => $attachment->getMimeType(),
                    'size_bytes' => $attachment->getSize(),
                    'storage_path' => $path,
                ]);
            }
        }
    }

    /**
     * Apply a local action to the matching remote message, best-effort. Used
     * by ApplyEmailFlagJob so archive/delete/read/move state eventually
     * syncs back to the mail server without blocking the request that
     * triggered it. $action is 'mark_read' | 'mark_unread' | 'delete' or
     * 'move:<local folder name>'.
     *
     * $sourceUid overrides $email->uid for locating the remote message.
     * Needed for moves: the controller nulls the local uid column before
     * dispatching (its old value could collide with an unrelated message
     * already filed under the destination folder, since IMAP UIDs are only
     * unique per-folder), so by the time this job runs $email->uid no
     * longer reflects where the message actually sits on the server.
     */
    public function applyAction(Email $email, string $action, ?string $sourceUid = null): void
    {
        $account = $email->mailAccount;
        $remotePath = $email->remote_folder_path ?: $email->folder;

        $client = $this->buildClient($account);
        $client->connect();

        $folder = $client->getFolder($remotePath);

        if (! $folder) {
            return;
        }

        $message = $folder->messages()->getMessageByUid((int) ($sourceUid ?? $email->uid));

        if (str_starts_with($action, 'move:')) {
            $this->applyMove($email, $account, $message, substr($action, 5));

            return;
        }

        match ($action) {
            'mark_read' => $message->setFlag('Seen'),
            'mark_unread' => $message->unsetFlag('Seen'),
            'delete' => $message->delete(expunge: true, trash_path: $this->guessTrashPath($client)),
            default => null,
        };
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
        $moved = $message->move($targetPath, expunge: true);

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
            return (int) ($folder->examine()['exists'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
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

    protected function syncFolder(MailAccount $account, Folder $folder, MailFolder $folderRecord, string $folderName, int $limit): void
    {
        $query = $folder->messages()->setFetchBody(false)->limit($limit)->fetchOrderDesc();
        $incremental = false;

        if ($folderRecord->last_uid > 0) {
            // Incremental: only fetch messages with a UID higher than the last
            // one we saw. IMAP UIDs are monotonically increasing per folder, so
            // this is safe as long as the UIDVALIDITY hasn't changed. If the
            // server reports a new UIDVALIDITY the folder was recreated and we
            // need a full resync — reset last_uid to 0 and fetch everything.
            $examined = $folder->examine();
            $serverUidValidity = (int) ($examined['uidvalidity'] ?? 0);

            if ($serverUidValidity > 0 && $serverUidValidity !== (int) ($folderRecord->uid_validity ?? $serverUidValidity)) {
                $folderRecord->update(['last_uid' => 0, 'uid_validity' => $serverUidValidity]);
            } else {
                $folderRecord->update(['uid_validity' => $serverUidValidity]);
                $incremental = true;
            }
        }

        // whereUidGreaterOrEqual() isn't supported by the installed
        // webklex/php-imap version — getByUidGreaterOrEqual() filters the
        // folder's UID list client-side instead.
        $messages = $incremental
            ? $query->getByUidGreaterOrEqual($folderRecord->last_uid + 1)
            : $query->all()->get();
        $highestUid = $folderRecord->last_uid;

        foreach ($messages as $message) {
            $uid = (string) $message->getUid();
            $numericUid = (int) $uid;

            if ($numericUid > $highestUid) {
                $highestUid = $numericUid;
            }

            $existing = Email::where('mail_account_id', $account->id)
                ->where('folder', $folderName)
                ->where('uid', $uid)
                ->first();

            if ($existing) {
                // Reconcile read flag — catches messages read on another device
                // since last sync.
                $serverIsRead = $message->getFlags()->has('Seen');
                if ($existing->is_read !== $serverIsRead) {
                    $existing->update(['is_read' => $serverIsRead]);
                }

                continue;
            }

            $messageId = $message->getMessageId()?->toString() ?: null;
            [$inReplyTo, $references] = $this->threadingHeaders($message);
            $threadId = $references[0] ?? $inReplyTo ?? $messageId ?: "standalone:{$account->id}:{$folderName}:{$uid}";

            $fromAddress = $message->getFrom()[0]->mail ?? null;
            $fromName = $message->getFrom()[0]->personal ?? null;
            $toAddresses = $this->addressesToArray($message->getTo()?->toArray());
            $ccAddresses = $this->addressesToArray($message->getCc()?->toArray());

            $subject = $message->getSubject()->toString() ?: '(no subject)';
            $sentAt = $message->getDate()?->toDate();
            $isRead = $message->getFlags()->has('Seen');

            Email::create([
                'mail_account_id' => $account->id,
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
                'has_attachments' => false,
                'sent_at' => $sentAt,
            ]);

            if ($folderName === 'INBOX' && ! $isRead && $folderRecord->last_uid > 0) {
                broadcast(new NewEmailArrived(
                    userId: $account->user_id,
                    emailId: 0,
                    folder: $folderName,
                    fromAddress: $fromAddress ?? '',
                    fromName: $fromName,
                    subject: $subject,
                    sentAt: $sentAt?->toISOString() ?? now()->toISOString(),
                ));

                if (config('features.macos_notifications')) {
                    $this->notifyMacOs($fromName ?? $fromAddress ?? 'New message', $subject);
                }
            }

            $this->recordContacts($account, $fromAddress, $fromName, $toAddresses, $ccAddresses);
        }

        if ($highestUid > $folderRecord->last_uid) {
            $folderRecord->update(['last_uid' => $highestUid]);
        }
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
            $references = array_values(array_filter($message->getReferences()?->toArray() ?? []));
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
    protected function addressesToArray(?array $addresses): array
    {
        if (! $addresses) {
            return [];
        }

        return collect($addresses)
            ->map(fn ($a) => trim(($a->personal ? $a->personal.' ' : '')."<{$a->mail}>"))
            ->values()
            ->all();
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
        $cm = new ClientManager;

        $config = [
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            'encryption' => $account->imap_encryption,
            'validate_cert' => true,
            'username' => $account->imap_username,
            // Without this, a stalled connection (wrong host, firewall
            // silently dropping packets, etc.) hangs until the job's own
            // 2-hour timeout — not something a "syncing" status should ever
            // visibly sit at.
            'timeout' => 30,
        ];

        if ($account->usesOAuth()) {
            $accessToken = $this->tokenRefresher->freshAccessToken($account);
            $config['password'] = $accessToken;
            $config['authentication'] = 'oauth';
        } else {
            $config['password'] = $account->imap_password;
        }

        return $cm->make($config);
    }
}
