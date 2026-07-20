<?php

namespace App\Services\Mail;

use App\Events\NewEmailArrived;
use App\Models\Contact;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\MailFolder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
        $body = $data['body'] ?? [];
        $isHtml = ($body['contentType'] ?? 'text') === 'html';

        $email->update([
            'body_html' => $isHtml ? ($body['content'] ?? null) : null,
            'body_text' => $isHtml ? null : ($body['content'] ?? null),
            'has_attachments' => $data['hasAttachments'] ?? false,
        ]);

        if (($data['hasAttachments'] ?? false) && $email->attachments()->count() === 0) {
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
            'uid' => $moved['id'],
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

        foreach ($response->json('value') ?? [] as $folder) {
            $id = $folder['id'];

            if (isset($result[$id]) || in_array($id, $excludedIds, true)) {
                continue;
            }

            $result[$id] = $folder['displayName'];
        }

        return $result;
    }

    /** @return array{id: string, displayName: string}|null */
    protected function getWellKnownFolder(string $accessToken, string $wellKnownName): ?array
    {
        $response = Http::withToken($accessToken)->get(self::BASE_URL."/me/mailFolders/{$wellKnownName}");

        return $response->successful() ? $response->json() : null;
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

        foreach ($data['value'] ?? [] as $message) {
            if (isset($message['@removed'])) {
                continue;
            }

            $this->storeMessage($account, $localName, $graphFolderId, $message, broadcastNew: $wasCaughtUp);
        }

        $nextLink = $data['@odata.nextLink'] ?? $data['@odata.deltaLink'] ?? null;

        if ($nextLink) {
            $folderRecord->update(['delta_link' => $nextLink]);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function storeMessage(MailAccount $account, string $folderName, string $graphFolderId, array $message, bool $broadcastNew): void
    {
        $uid = $message['id'];

        $existing = Email::where('mail_account_id', $account->id)
            ->where('folder', $folderName)
            ->where('uid', $uid)
            ->first();

        if ($existing) {
            $isRead = $message['isRead'] ?? false;

            if ($existing->is_read !== $isRead) {
                $existing->update(['is_read' => $isRead]);
            }

            return;
        }

        $messageId = $message['internetMessageId'] ?? null;

        $ulid = $messageId
            ? Email::where('mail_account_id', $account->id)
                ->where('message_id', $messageId)
                ->value('ulid')
            : null;

        $threadId = $message['conversationId'] ?? $messageId ?: "standalone:{$account->id}:{$folderName}:{$uid}";

        $fromAddress = $message['from']['emailAddress']['address'] ?? null;
        $fromName = $message['from']['emailAddress']['name'] ?? null;
        $toAddresses = $this->graphAddressesToArray($message['toRecipients'] ?? []);
        $ccAddresses = $this->graphAddressesToArray($message['ccRecipients'] ?? []);

        $subject = $message['subject'] ?: '(no subject)';
        $sentAt = isset($message['receivedDateTime']) ? Carbon::parse($message['receivedDateTime']) : null;
        $isRead = $message['isRead'] ?? false;

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
                fromAddress: $fromAddress ?? '',
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
    protected function graphAddressesToArray(array $recipients): array
    {
        return collect($recipients)
            ->map(function ($r) {
                $address = $r['emailAddress']['address'] ?? null;
                $name = $r['emailAddress']['name'] ?? null;

                return $address ? trim(($name ? $name.' ' : '')."<{$address}>") : null;
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

        foreach ($response->json('value') ?? [] as $attachment) {
            if (($attachment['@odata.type'] ?? null) !== '#microsoft.graph.fileAttachment') {
                continue;
            }

            $path = "email-attachments/{$account->id}/{$email->id}/".$attachment['name'];
            Storage::disk('local')->put($path, base64_decode($attachment['contentBytes']));

            EmailAttachment::create([
                'email_id' => $email->id,
                'filename' => $attachment['name'],
                'mime_type' => $attachment['contentType'] ?? 'application/octet-stream',
                'size_bytes' => $attachment['size'] ?? 0,
                'storage_path' => $path,
            ]);
        }
    }
}
