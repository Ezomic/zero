<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Jobs\ApplyEmailFlagJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\ImapSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InboxController extends Controller
{
    use InteractsWithCurrentUser;

    /** Fallback tabs shown when no specific account is selected — once an
     *  account is chosen, its own discovered folders (mail_folders) are used
     *  instead, since different accounts can have entirely different custom
     *  folders/labels. */
    protected const GENERIC_FOLDERS = ['INBOX', 'SENT', 'DRAFTS', 'TRASH'];

    /** Canonical folders always sort first, ahead of custom folders. */
    protected const CANONICAL_ORDER = ['INBOX', 'SENT', 'DRAFTS', 'TRASH'];

    /**
     * Unified inbox: emails from every account the user owns, newest first,
     * collapsed to one row per conversation thread. Filter by ?account=ID
     * for a single mailbox (revealing that account's own folder tabs),
     * ?folder=<name>, or ?archived=1. ?open=<id> preloads that conversation
     * into the inline reading pane (used when the AJAX panel-switch JS isn't
     * available, or on direct navigation from elsewhere with a target thread).
     */
    public function index(Request $request, ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): View
    {
        $selectedAccountId = $request->filled('account') ? $request->integer('account') : null;
        $availableFolders = $this->foldersFor($selectedAccountId);
        $folder = in_array($request->get('folder'), $availableFolders, true) ? $request->get('folder') : 'INBOX';
        $showArchived = $request->boolean('archived');

        $viewData = $this->listData($selectedAccountId, $folder, $showArchived, $request->string('q')->toString() ?: null, $availableFolders);
        $viewData['openThread'] = null;

        if ($request->filled('open')) {
            $openEmail = Email::find($request->integer('open'));

            if ($openEmail && $openEmail->mailAccount?->user_id === auth()->id()) {
                $viewData['openThread'] = $this->openedThreadData($openEmail, $imapSyncService, $graphMailSyncService);
            }
        }

        return view('inbox.index', $viewData);
    }

    /**
     * Deep-linkable single-conversation view: renders the same 3-pane inbox
     * as index(), but with the list scoped to this email's own folder/
     * account/archived state (so e.g. opening a Sent-folder email shows the
     * Sent tab with it visible) and this conversation preloaded into the
     * reading pane. Opening a conversation marks every message in it read,
     * mirroring Gmail-style thread semantics, and lazily fetches the body of
     * any message that hasn't been fetched yet (bulk sync only pulls headers).
     */
    public function show(Email $email, ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): View
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        $selectedAccountId = $email->mail_account_id;
        $showArchived = $email->is_archived;
        $folder = $email->folder;
        $availableFolders = $this->foldersFor($selectedAccountId);

        if (! $showArchived && ! in_array($folder, $availableFolders, true)) {
            $availableFolders[] = $folder;
        }

        $viewData = $this->listData($selectedAccountId, $folder, $showArchived, null, $availableFolders);
        $viewData['openThread'] = $this->openedThreadData($email, $imapSyncService, $graphMailSyncService);

        return view('inbox.index', $viewData);
    }

    /**
     * Resolves a durable cross-app mail link (/emails/ref/{ulid}) to the live
     * message and redirects to its canonical view. A message that was moved
     * between folders can exist as several rows sharing one ULID (plus stale
     * orphans from out-of-band moves); latest('id') picks the most recent
     * live row, so the link survives folder moves.
     */
    public function showByRef(string $ulid): RedirectResponse
    {
        $email = Email::where('ulid', $ulid)
            ->where('is_deleted', false)
            ->latest('id')
            ->firstOrFail();

        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        return redirect()->route('inbox.show', $email);
    }

    /**
     * AJAX endpoint behind the inline reading pane: returns just the
     * reading-pane HTML fragment for $email, so the inbox list JS can swap
     * threads in place instead of a full page navigation. Shares the exact
     * same read/fetch-body side effects as show().
     */
    public function panel(Email $email, ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): View
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        return view('inbox._reading_pane', $this->openedThreadData($email, $imapSyncService, $graphMailSyncService));
    }

    /**
     * Builds the paginated thread list + its filter chrome for the given
     * account/folder/archived/search scope. Shared by index() (request-
     * driven filters) and show() (filters derived from the opened email).
     *
     * @param  array<int, string>  $availableFolders
     * @return array<string, mixed>
     */
    protected function listData(?int $selectedAccountId, string $folder, bool $showArchived, ?string $q, array $availableFolders): array
    {
        $accountIds = $this->currentUser()->mailAccounts()->pluck('id');

        $base = Email::query()
            ->whereIn('mail_account_id', $accountIds)
            ->where('is_deleted', false);

        if ($showArchived) {
            $base->where('is_archived', true);
        } else {
            $base->where('folder', $folder)->where('is_archived', false);
        }

        if ($selectedAccountId) {
            $base->where('mail_account_id', $selectedAccountId);
        }

        if ($q) {
            $base->whereIn('id', $this->searchEmailIds($q, $accountIds->all()));
        }

        // Collapse to the latest message per conversation thread.
        $threadEmailIds = (clone $base)->reorder()
            ->selectRaw('MAX(id) as id')
            ->groupBy('thread_id')
            ->pluck('id');

        $emails = Email::query()
            ->whereIn('id', $threadEmailIds)
            ->with('mailAccount')
            ->latest('sent_at')
            ->paginate(25)
            ->withQueryString();

        $threadCounts = Email::query()
            ->whereIn('thread_id', $emails->pluck('thread_id'))
            ->where('is_deleted', false)
            ->select('thread_id', DB::raw('count(*) as cnt'))
            ->groupBy('thread_id')
            ->pluck('cnt', 'thread_id');

        return [
            'emails' => $emails,
            'accounts' => $this->currentUser()->mailAccounts()->get(),
            'folder' => $folder,
            'showArchived' => $showArchived,
            'threadCounts' => $threadCounts,
            'folders' => $availableFolders,
            'selectedAccountId' => $selectedAccountId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function openedThreadData(Email $email, ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): array
    {
        $messages = $email->threadMessages()->get();
        $syncService = $email->requireMailAccount()->provider === MailAccount::PROVIDER_OUTLOOK ? $graphMailSyncService : $imapSyncService;

        foreach ($messages as $message) {
            if ($message->body_html === null && $message->body_text === null) {
                try {
                    $syncService->fetchBody($message);
                    $message->refresh();
                } catch (\Throwable) {
                    // Leave the body empty — the view falls back gracefully.
                }
            }

            if (! $message->is_read) {
                $message->update(['is_read' => true]);
                ApplyEmailFlagJob::dispatch($message, 'mark_read');
            }
        }

        $availableFolders = $this->foldersFor($email->mail_account_id);
        $suggestedFolder = $email->suggestedFolder();

        if ($suggestedFolder && ! in_array($suggestedFolder, $availableFolders, true)) {
            $suggestedFolder = null;
        }

        return [
            'messages' => $messages,
            'email' => $email,
            'availableFolders' => $availableFolders,
            'suggestedFolder' => $suggestedFolder,
        ];
    }

    public function archive(Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);
        $this->threadEmails($email)->update(['is_archived' => true]);

        return back()->with('status', 'Conversation archived.');
    }

    public function unarchive(Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);
        $this->threadEmails($email)->update(['is_archived' => false]);

        return back()->with('status', 'Conversation moved back to inbox.');
    }

    public function markUnread(Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);

        foreach ($this->threadEmails($email)->get() as $message) {
            $message->update(['is_read' => false]);
            ApplyEmailFlagJob::dispatch($message, 'mark_unread');
        }

        return redirect()->route('inbox.index')->with('status', 'Marked as unread.');
    }

    public function destroy(Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);

        foreach ($this->threadEmails($email)->get() as $message) {
            $message->update(['is_deleted' => true]);
            ApplyEmailFlagJob::dispatch($message, 'delete');
        }

        return redirect()->route('inbox.index')->with('status', 'Conversation deleted.');
    }

    /**
     * Moves every message in the conversation to another folder (custom or
     * canonical) on the same account. Removes it from Inbox both locally and
     * — once the async job runs — on the mail server, since a real IMAP
     * move relabels the message rather than just copying it.
     */
    public function move(Request $request, Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);

        $data = $request->validate([
            'folder' => ['required', 'string'],
        ]);

        $targetExists = MailFolder::where('mail_account_id', $email->mail_account_id)
            ->where('local_name', $data['folder'])
            ->exists();

        abort_unless($targetExists, 422, 'Unknown target folder.');

        foreach ($this->threadEmails($email)->get() as $message) {
            // The old uid was only ever valid within the source folder — IMAP
            // UIDs aren't unique across folders, so keeping it here risks
            // colliding with an unrelated message already filed under the
            // destination folder. Null it locally (the job carries the old
            // value separately so it can still find the real message) until
            // ApplyEmailFlagJob's real move reports back the uid the message
            // actually got in its destination.
            $sourceUid = $message->uid;
            $message->update(['folder' => $data['folder'], 'uid' => null]);
            ApplyEmailFlagJob::dispatch($message, 'move:'.$data['folder'], $sourceUid);
        }

        return redirect()->route('inbox.index')->with('status', 'Moved to '.$data['folder'].'.');
    }

    /**
     * Bulk archive/unarchive/delete/read/unread from the inbox list's
     * checkbox selection. Each selected row represents a whole thread, so
     * the action cascades to every message in it.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:archive,unarchive,delete,read,unread'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $accountIds = $this->currentUser()->mailAccounts()->pluck('id');

        $selected = Email::whereIn('id', $data['ids'])
            ->whereIn('mail_account_id', $accountIds)
            ->get();

        $messages = Email::whereIn('mail_account_id', $accountIds)
            ->whereIn('thread_id', $selected->pluck('thread_id'))
            ->get()
            ->unique('id');

        foreach ($messages as $message) {
            switch ($data['action']) {
                case 'archive':
                    $message->update(['is_archived' => true]);
                    break;
                case 'unarchive':
                    $message->update(['is_archived' => false]);
                    break;
                case 'delete':
                    $message->update(['is_deleted' => true]);
                    ApplyEmailFlagJob::dispatch($message, 'delete');
                    break;
                case 'read':
                    $message->update(['is_read' => true]);
                    ApplyEmailFlagJob::dispatch($message, 'mark_read');
                    break;
                case 'unread':
                    $message->update(['is_read' => false]);
                    ApplyEmailFlagJob::dispatch($message, 'mark_unread');
                    break;
            }
        }

        return back()->with('status', count($selected).' conversation(s) updated.');
    }

    /**
     * Returns HTML fragments for new inbox emails that arrived after ?since=<id>.
     * Only applies to the first page of the unified inbox (no search, page 1).
     */
    public function newEmails(Request $request): JsonResponse
    {
        $since = $request->integer('since', 0);
        $selectedAccountId = $request->filled('account') ? $request->integer('account') : null;
        $folder = $request->get('folder', 'INBOX');
        $showArchived = $request->boolean('archived');

        $accountIds = $this->currentUser()->mailAccounts()->pluck('id');

        $base = Email::query()
            ->whereIn('mail_account_id', $accountIds)
            ->where('is_deleted', false)
            ->where('id', '>', $since);

        if ($showArchived) {
            $base->where('is_archived', true);
        } else {
            $base->where('folder', $folder)->where('is_archived', false);
        }

        if ($selectedAccountId) {
            $base->where('mail_account_id', $selectedAccountId);
        }

        $threadEmailIds = (clone $base)->reorder()
            ->selectRaw('MAX(id) as id')
            ->groupBy('thread_id')
            ->pluck('id');

        $emails = Email::whereIn('id', $threadEmailIds)
            ->with('mailAccount')
            ->latest('sent_at')
            ->get();

        if ($emails->isEmpty()) {
            return response()->json(['html' => [], 'newest_id' => $since]);
        }

        $threadCounts = Email::whereIn('thread_id', $emails->pluck('thread_id'))
            ->where('is_deleted', false)
            ->select('thread_id', DB::raw('count(*) as cnt'))
            ->groupBy('thread_id')
            ->pluck('cnt', 'thread_id');

        $html = $emails->map(fn ($email) => view('inbox._email_row', ['email' => $email, 'threadCounts' => $threadCounts])->render());

        return response()->json([
            'html' => $html,
            'newest_id' => $emails->max('id'),
        ]);
    }

    /**
     * Polled by the sidebar badge to approximate real-time new-mail
     * notifications without needing a websocket server.
     */
    public function unreadCount(): JsonResponse
    {
        $accountIds = $this->currentUser()->mailAccounts()->pluck('id');

        $total = Email::whereIn('mail_account_id', $accountIds)
            ->where('folder', 'INBOX')
            ->where('is_read', false)
            ->where('is_archived', false)
            ->where('is_deleted', false)
            ->count();

        return response()->json(['unread' => $total]);
    }

    /**
     * The folder tabs to show: a specific account's own discovered folders
     * (from mail_folders, canonical ones first, then custom alphabetically),
     * or the generic canonical set when no single account is selected.
     */
    /** @return array<int, string> */
    protected function foldersFor(?int $accountId): array
    {
        if (! $accountId) {
            return self::GENERIC_FOLDERS;
        }

        $names = MailFolder::where('mail_account_id', $accountId)
            ->pluck('local_name')
            ->unique()
            ->values()
            ->all();

        if (empty($names)) {
            return self::GENERIC_FOLDERS;
        }

        usort($names, function ($a, $b) {
            $ai = array_search($a, self::CANONICAL_ORDER, true);
            $bi = array_search($b, self::CANONICAL_ORDER, true);

            return match (true) {
                $ai !== false && $bi !== false => $ai <=> $bi,
                $ai !== false => -1,
                $bi !== false => 1,
                default => strcasecmp($a, $b),
            };
        });

        return $names;
    }

    /**
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    protected function searchEmailIds(string $q, array $accountIds): array
    {
        if (DB::getDriverName() === 'sqlite') {
            $match = $this->toFtsQuery($q);

            if ($match !== '') {
                try {
                    return DB::table('emails_fts')
                        ->select('rowid')
                        ->whereRaw('emails_fts MATCH ?', [$match])
                        ->pluck('rowid')
                        ->all();
                } catch (\Throwable) {
                    // Fall through to LIKE search below.
                }
            }
        }

        return Email::whereIn('mail_account_id', $accountIds)
            ->where(function ($query) use ($q) {
                $query->where('subject', 'like', "%{$q}%")
                    ->orWhere('from_address', 'like', "%{$q}%")
                    ->orWhere('body_text', 'like', "%{$q}%");
            })
            ->pluck('id')
            ->all();
    }

    protected function toFtsQuery(string $q): string
    {
        $terms = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = array_map(
            fn ($term) => '"'.str_replace('"', '""', $term).'"*',
            $terms
        );

        return implode(' AND ', $terms);
    }

    protected function authorizeOwnership(Email $email): void
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);
    }

    /** @return Builder<Email> */
    protected function threadEmails(Email $email): Builder
    {
        return Email::where('mail_account_id', $email->mail_account_id)
            ->where('thread_id', $email->thread_id);
    }
}
