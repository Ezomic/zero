<?php

namespace App\Services\Mail;

use App\Events\NewEmailArrived;
use App\Models\Contact;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Support\Payload;
use App\Support\StoredAttachmentName;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads Outlook/Hotmail mail via the Microsoft Graph Mail API instead of IMAP
 * — see ZERO-20. Microsoft retired new consent grants for the legacy
 * IMAP.AccessAsUser.All scope, so Outlook accounts can no longer read over
 * raw IMAP; sending already went through Graph's /me/sendMail.
 *
 * ## Sync strategy
 *
 * Graph's delta query (`/me/mailFolders/{id}/messages/delta`) replaces IMAP's
 * UID tracking. Each call returns one page of new/changed messages plus
 * either `@odata.nextLink` (more pages queued) or `@odata.deltaLink` (fully
 * caught up — this becomes the bookmark for the next incremental check).
 * Whichever URL comes back is stored as-is in `mail_folders.delta_link` and
 * replayed verbatim next sync — no stored link at all means "start from
 * scratch". Only one page is processed per sync() call per folder, so a
 * large mailbox's first backfill spreads checkpointed progress across
 * multiple 5-minute scheduler runs instead of risking the job's timeout
 * mid-fetch, mirroring ImapSyncService's chunked full-sync checkpointing.
 *
 * `delta_link` values are distinguishable without a separate flag: a
 * deltaLink always contains `deltatoken=`, a nextLink always contains
 * `skiptoken=`. New-mail broadcasting is only enabled once a folder has
 * reached a real deltaLink at least once (i.e. finished its historical
 * backfill) — otherwise a multi-run backfill would flood the UI with years
 * of old mail, the same concern IMAP's `last_uid > 0` guard addresses.
 */
class GraphMailSyncService
{
    protected const BASE_URL = 'https://graph.microsoft.com/v1.0';

    /** Well-known Graph folder names mapped to this app's canonical local names. */
    protected const WELL_KNOWN_FOLDERS = [
        'inbox' => 'INBOX',
        'sentitems' => 'SENT',
        'drafts' => 'DRAFTS',
        'deleteditems' => 'TRASH',
    ];

    /** Well-known folders that are aggregate/system views, never synced as their own folder. */
    protected const EXCLUDED_WELL_KNOWN_FOLDERS = [
        'junkemail', 'archive', 'outbox', 'conversationhistory', 'clutter',
    ];

    /** Messages fetched per delta page — kept small so one HTTP round trip
     *  checkpoints quickly, same rationale as ImapSyncService::FULL_SYNC_CHUNK_SIZE. */
    protected const PAGE_SIZE = 50;

    protected const MESSAGE_SELECT = 'subject,from,toRecipients,ccRecipients,receivedDateTime,isRead,conversationId,internetMessageId,hasAttachments';

    public function __construct(
        protected OAuthTokenRefresher $tokenRefresher,
    ) {}

    public function sync(MailAccount $account): void
    {
        $account->update(['sync_status' => 'syncing', 'sync_status_since' => now(), 'sync_error' => null]);

        try {
            $accessToken = $this->tokenRefresher->freshAccessToken($account);

            $folders = $this->foldersToSync($accessToken);

            foreach ($folders as $graphFolderId => $localName) {
                $folderRecord = MailFolder::updateOrCreate(
                    ['mail_account_id' => $account->id, 'remote_path' => $graphFolderId],
                    ['local_name' => $localName]
                );

                $this->syncFolderPage($account, $accessToken, $graphFolderId, $localName, $folderRecord);
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

    public function fetchBody(Email $email): void
    {
        $account = $email->requireMailAccount();
        $accessToken = $this->tokenRefresher->freshAccessToken($account);

        $response = Http::withToken($accessToken)
            ->get(self::BASE_URL."/me/messages/{$email->uid}", ['$select' => 'body,hasAttachments']);

        if ($response->failed()) {
            throw new RuntimeException('Graph fetchBody failed: '.$response->body());
        }

        $data = $response->json();
        $body = Payload::arr($data, 'body');
        $isHtml = Payload::str($body, 'contentType') === 'html';
        $content = Payload::nullableStr($body, 'content');
        $hasAttachments = Payload::bool($data, 'hasAttachments');

        $email->update([
            'body_html' => $isHtml ? $content : null,
            'body_text' => $isHtml ? null : $content,
            'has_attachments' => $hasAttachments,
        ]);

        if ($hasAttachments && $email->attachments()->count() === 0) {
            $this->storeAttachments($account, $email, $accessToken);
        }
    }

    public function applyAction(Email $email, string $action, ?string $sourceUid = null): void
    {
        $account = $email->requireMailAccount();
        $accessToken = $this->tokenRefresher->freshAccessToken($account);
        $messageId = $sourceUid ?? $email->uid;

        if ($messageId === null) {
            throw new RuntimeException("Email {$email->id} has no remote uid to act on.");
        }

        if (str_starts_with($action, 'move:')) {
            $this->applyMove($email, $account, $accessToken, $messageId, substr($action, 5));

            return;
        }

        $response = match ($action) {
            'mark_read' => Http::withToken($accessToken)->patch(self::BASE_URL."/me/messages/{$messageId}", ['isRead' => true]),
            'mark_unread' => Http::withToken($accessToken)->patch(self::BASE_URL."/me/messages/{$messageId}", ['isRead' => false]),
            'delete' => Http::withToken($accessToken)->delete(self::BASE_URL."/me/messages/{$messageId}"),
            default => null,
        };

        if ($response?->failed()) {
            throw new RuntimeException("Graph applyAction({$action}) failed: ".$response->body());
        }
    }

    protected function applyMove(Email $email, MailAccount $account, string $accessToken, string $messageId, string $targetLocalName): void
    {
        $targetFolderId = MailFolder::where('mail_account_id', $account->id)
            ->where('local_name', $targetLocalName)
            ->value('remote_path');

        if (! $targetFolderId) {
            throw new RuntimeException("Unknown target folder: {$targetLocalName}");
        }

        $response = Http::withToken($accessToken)
            ->post(self::BASE_URL."/me/messages/{$messageId}/move", ['destinationId' => $targetFolderId]);

        if ($response->failed()) {
            throw new RuntimeException('Graph move failed: '.$response->body());
        }

        $moved = $response->json();

        $email->update([
            'uid' => Payload::str($moved, 'id'),
            'remote_folder_path' => $targetFolderId,
        ]);
    }

    /**
     * @return array<string, string> Graph folder id => local name
     */
    protected function foldersToSync(string $accessToken): array
    {
        $result = [];
        $excludedIds = [];

        foreach (self::WELL_KNOWN_FOLDERS as $wellKnownName => $localName) {
            $folder = $this->getWellKnownFolder($accessToken, $wellKnownName);

            if ($folder) {
                $result[$folder['id']] = $localName;
            }
        }

        foreach (self::EXCLUDED_WELL_KNOWN_FOLDERS as $wellKnownName) {
            $folder = $this->getWellKnownFolder($accessToken, $wellKnownName);

            if ($folder) {
                $excludedIds[] = $folder['id'];
            }
        }

        $response = Http::withToken($accessToken)
            ->get(self::BASE_URL.'/me/mailFolders', ['$top' => 250]);

        if ($response->failed()) {
            throw new RuntimeException('Graph folder listing failed: '.$response->body());
        }

        foreach (Payload::arr($response->json(), 'value') as $folder) {
            $id = Payload::str($folder, 'id');

            if ($id === '' || isset($result[$id]) || in_array($id, $excludedIds, true)) {
                continue;
            }

            $result[$id] = Payload::str($folder, 'displayName');
        }

        return $result;
    }

    /** @return array{id: string, displayName: string}|null */
    protected function getWellKnownFolder(string $accessToken, string $wellKnownName): ?array
    {
        $response = Http::withToken($accessToken)->get(self::BASE_URL."/me/mailFolders/{$wellKnownName}");

        if (! $response->successful()) {
            return null;
        }

        $folder = $response->json();

        return ['id' => Payload::str($folder, 'id'), 'displayName' => Payload::str($folder, 'displayName')];
    }

    protected function syncFolderPage(MailAccount $account, string $accessToken, string $graphFolderId, string $localName, MailFolder $folderRecord): void
    {
        $wasCaughtUp = $folderRecord->delta_link !== null && str_contains($folderRecord->delta_link, 'deltatoken=');

        // A stored delta_link already carries its own $deltatoken/$skiptoken
        // query string — passing an empty query array to Http::get() would
        // strip it (Guzzle treats an explicit 'query' option, even empty, as
        // authoritative over whatever the URL already contains), so replay
        // it as a bare URL instead of appending one.
        $response = $folderRecord->delta_link
            ? Http::withToken($accessToken)->get($folderRecord->delta_link)
            : Http::withToken($accessToken)->get(
                self::BASE_URL."/me/mailFolders/{$graphFolderId}/messages/delta",
                ['$select' => self::MESSAGE_SELECT, '$top' => self::PAGE_SIZE]
            );

        if ($response->status() === 410) {
            // Delta token expired — drop it and let the next run start over.
            $folderRecord->update(['delta_link' => null]);

            return;
        }

        if ($response->failed()) {
            throw new RuntimeException("Graph delta sync failed for folder {$graphFolderId}: ".$response->body());
        }

        $data = $response->json();

        foreach (Payload::arr($data, 'value') as $message) {
            if (! is_array($message)) {
                continue;
            }

            $keyed = [];

            foreach ($message as $key => $value) {
                $keyed[(string) $key] = $value;
            }

            // Delta reports removals explicitly, and skipping them is why a
            // message deleted in Outlook or on a phone stayed here forever
            // (ZERO-103). This is the Graph counterpart of the UID-list
            // reconciliation ZERO-90 added for IMAP, and cheaper: the
            // information is already in the response.
            if (isset($keyed['@removed'])) {
                $this->markRemoved($account, $localName, $keyed);

                continue;
            }

            $this->storeMessage($account, $localName, $graphFolderId, $keyed, broadcastNew: $wasCaughtUp);
        }

        $nextLink = Payload::nullableStr($data, '@odata.nextLink') ?? Payload::nullableStr($data, '@odata.deltaLink');

        if ($nextLink) {
            $folderRecord->update(['delta_link' => $nextLink]);
        }
    }

    /**
     * Marks the local copy of a message delta says is gone from this folder.
     *
     * Both removal reasons are treated the same. 'deleted' is self-evident;
     * 'changed' means it left this folder's result set some other way, which
     * for our purposes is the same thing, since a message moved elsewhere
     * arrives as an addition in the destination folder's own delta and rows
     * here are per (account, folder, uid) anyway.
     *
     * A message with an action of ours still queued is left alone, matching
     * the IMAP reconciliation: until the drain runs, what the server reports
     * and what the user asked for are legitimately out of step.
     *
     * @param  array<string, mixed>  $message
     */
    protected function markRemoved(MailAccount $account, string $folderName, array $message): void
    {
        $uid = Payload::str($message, 'id');

        if ($uid === '') {
            return;
        }

        Email::query()
            ->where('mail_account_id', $account->id)
            ->where('folder', $folderName)
            ->where('uid', $uid)
            ->where('is_deleted', false)
            ->whereNotExists(function (QueryBuilder $query) use ($account): void {
                $query->select(DB::raw('1'))
                    ->from('pending_mirror_actions')
                    ->where('pending_mirror_actions.mail_account_id', $account->id)
                    ->whereColumn('pending_mirror_actions.email_id', 'emails.id');
            })
            ->update(['is_deleted' => true]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function storeMessage(MailAccount $account, string $folderName, string $graphFolderId, array $message, bool $broadcastNew): void
    {
        $uid = Payload::str($message, 'id');

        $existing = Email::where('mail_account_id', $account->id)
            ->where('folder', $folderName)
            ->where('uid', $uid)
            ->first();

        if ($existing) {
            $isRead = Payload::bool($message, 'isRead');

            if ($existing->is_read !== $isRead) {
                $existing->update(['is_read' => $isRead]);
            }

            return;
        }

        $messageId = Payload::nullableStr($message, 'internetMessageId');

        $ulid = $messageId
            ? Email::where('mail_account_id', $account->id)
                ->where('message_id', $messageId)
                ->value('ulid')
            : null;

        $threadId = Payload::nullableStr($message, 'conversationId') ?? $messageId ?: "standalone:{$account->id}:{$folderName}:{$uid}";

        $fromAddress = Payload::str($message, 'from', 'emailAddress', 'address');
        $fromName = Payload::nullableStr($message, 'from', 'emailAddress', 'name');
        $toAddresses = $this->graphAddressesToArray(Payload::arr($message, 'toRecipients'));
        $ccAddresses = $this->graphAddressesToArray(Payload::arr($message, 'ccRecipients'));

        $subject = Payload::nullableStr($message, 'subject') ?? '(no subject)';
        $receivedAt = Payload::nullableStr($message, 'receivedDateTime');
        $sentAt = $receivedAt !== null ? Carbon::parse($receivedAt) : null;
        $isRead = Payload::bool($message, 'isRead');

        $email = Email::create([
            'mail_account_id' => $account->id,
            'ulid' => $ulid,
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'folder' => $folderName,
            'remote_folder_path' => $graphFolderId,
            'uid' => $uid,
            'subject' => $subject,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'to_addresses' => $toAddresses,
            'cc_addresses' => $ccAddresses,
            'body_html' => null,
            'body_text' => null,
            'is_read' => $isRead,
            'has_attachments' => $message['hasAttachments'] ?? false,
            'sent_at' => $sentAt,
        ]);

        if ($folderName === 'INBOX' && ! $isRead && $broadcastNew) {
            broadcast(new NewEmailArrived(
                userId: $account->user_id,
                emailId: $email->id,
                folder: $folderName,
                fromAddress: $fromAddress,
                fromName: $fromName,
                subject: $subject,
                sentAt: ($sentAt ?? now())->toISOString() ?? '',
            ));
        }

        $this->recordContacts($account, $fromAddress, $fromName, $toAddresses, $ccAddresses);
    }

    /**
     * @param  array<int, array{emailAddress: array{address?: string, name?: string}}>  $recipients
     * @return array<int, string>
     */
    /**
     * @param  array<mixed>  $recipients
     * @return array<int, string>
     */
    protected function graphAddressesToArray(array $recipients): array
    {
        return collect($recipients)
            ->map(function (mixed $r): ?string {
                $address = Payload::nullableStr($r, 'emailAddress', 'address');
                $name = Payload::nullableStr($r, 'emailAddress', 'name');

                return $address !== null ? trim(($name !== null ? $name.' ' : '')."<{$address}>") : null;
            })
            ->filter()
            ->values()
            ->all();
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

    protected function storeAttachments(MailAccount $account, Email $email, string $accessToken): void
    {
        $response = Http::withToken($accessToken)->get(self::BASE_URL."/me/messages/{$email->uid}/attachments");

        if ($response->failed()) {
            return;
        }

        foreach (Payload::arr($response->json(), 'value') as $attachment) {
            if (Payload::str($attachment, '@odata.type') !== '#microsoft.graph.fileAttachment') {
                continue;
            }

            $name = Payload::str($attachment, 'name');

            // See ZERO-106: the sender picks this name, and one unstorable
            // attachment must not cost the message its others.
            try {
                $path = "email-attachments/{$account->id}/{$email->id}/".StoredAttachmentName::for($name);
                Storage::disk('local')->put($path, base64_decode(Payload::str($attachment, 'contentBytes')));

                EmailAttachment::create([
                    'email_id' => $email->id,
                    'filename' => $name,
                    'mime_type' => Payload::nullableStr($attachment, 'contentType') ?? 'application/octet-stream',
                    'size_bytes' => Payload::int($attachment, 'size'),
                    'storage_path' => $path,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Could not store attachment on email {$email->id}: ".$e->getMessage());
            }
        }
    }
}
