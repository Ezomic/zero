<?php

namespace App\Http\Controllers;

use App\Actions\Mail\QueueMirrorAction;
use App\Actions\Mail\ReadInvitations;
use App\Concerns\InteractsWithCurrentUser;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\ImapSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

    public function __construct(
        protected QueueMirrorAction $queueMirror,
        protected ReadInvitations $readInvitations,
    ) {}

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
            $this->applySearch($base, $q);
        }

        // Collapse to the latest message per conversation thread. Kept as a
        // subquery rather than a plucked list for the same reason as the
        // search above: one bound parameter per thread is one too many on a
        // folder holding more threads than SQLite will bind (ZERO-91).
        $latestPerThread = (clone $base)->reorder()
            ->selectRaw('MAX(id) as id')
            ->groupBy('thread_id');

        $emails = Email::query()
            ->whereIn('id', $latestPerThread)
            ->with('mailAccount')
            ->latest('sent_at')
            ->paginate(25)
            ->withQueryString();

        // Bounded by the page size, so this one stays a plain list.
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
                $this->queueMirror->handle($message, 'mark_read');
            }
        }

        $availableFolders = $this->foldersFor($email->mail_account_id);
        $suggestedFolder = $email->suggestedFolder();

        if ($suggestedFolder && ! in_array($suggestedFolder, $availableFolders, true)) {
            $suggestedFolder = null;
        }

        $configuredTimezone = config('app.timezone');

        return [
            'messages' => $messages,
            'email' => $email,
            'availableFolders' => $availableFolders,
            'suggestedFolder' => $suggestedFolder,
            'invitations' => $this->readInvitations->handle(
                $messages,
                is_string($configuredTimezone) ? $configuredTimezone : 'UTC',
            ),
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
            $this->queueMirror->handle($message, 'mark_unread');
        }

        return redirect()->route('inbox.index')->with('status', 'Marked as unread.');
    }

    public function destroy(Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);

        foreach ($this->threadEmails($email)->get() as $message) {
            $message->update(['is_deleted' => true]);
            $this->queueMirror->handle($message, 'delete');
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

        $validated = $request->validate([
            'folder' => ['required', 'string'],
        ]);

        $data = [];

        foreach (is_array($validated) ? $validated : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        $folder = $request->string('folder')->toString();

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
            // the real move reports back the uid the message
            // actually got in its destination.
            //
            // Queued before the row moves: QueueMirrorAction records
            // `remote_folder_path ?: folder`, so queueing after the update
            // stamps the destination as the action's source folder whenever
            // remote_folder_path is null, and the drain then looks for the
            // uid in the folder the message has not reached yet.
            $sourceUid = $message->uid;
            $this->queueMirror->handle($message, 'move:'.$folder, $sourceUid);
            $message->update(['folder' => $folder, 'uid' => null]);
        }

        return redirect()->route('inbox.index')->with('status', 'Moved to '.$folder.'.');
    }

    /**
     * Bulk archive/unarchive/delete/read/unread from the inbox list's
     * checkbox selection. Each selected row represents a whole thread, so
     * the action cascades to every message in it.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:archive,unarchive,delete,read,unread'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $data = [];

        foreach (is_array($validated) ? $validated : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

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
                    $this->queueMirror->handle($message, 'delete');
                    break;
                case 'read':
                    $message->update(['is_read' => true]);
                    $this->queueMirror->handle($message, 'mark_read');
                    break;
                case 'unread':
                    $message->update(['is_read' => false]);
                    $this->queueMirror->handle($message, 'mark_unread');
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

        // ?since=0 means "everything", so this is unbounded too — same
        // subquery treatment as listData().
        $latestPerThread = (clone $base)->reorder()
            ->selectRaw('MAX(id) as id')
            ->groupBy('thread_id');

        $emails = Email::whereIn('id', $latestPerThread)
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
                default => strcasecmp(is_string($a) ? $a : '', is_string($b) ? $b : ''),
            };
        });

        return array_map(fn (mixed $n): string => is_string($n) ? $n : '', $names);
    }

    /**
     * Narrows the list query to messages matching $q.
     *
     * Both paths constrain the query in place rather than resolving a list of
     * ids to feed back in. Pulling every match out first meant one bound
     * parameter per hit, and SQLite caps a statement at ~32k of them: a term
     * common enough to appear in tens of thousands of messages produced a
     * search that could only fail, and only on a mailbox large enough to
     * matter (ZERO-91). The FTS subquery below binds exactly once, whatever
     * it matches.
     *
     * @param  Builder<Email>  $base
     */
    protected function applySearch(Builder $base, string $q): void
    {
        $match = DB::getDriverName() === 'sqlite' ? $this->toFtsQuery($q) : '';

        if ($match !== '' && $this->ftsIsUsable($match)) {
            $base->whereIn('id', function (QueryBuilder $query) use ($match): void {
                $query->select('rowid')
                    ->from('emails_fts')
                    ->whereRaw('emails_fts MATCH ?', [$match]);
            });

            return;
        }

        $base->where(function (Builder $query) use ($q): void {
            $query->where('subject', 'like', "%{$q}%")
                ->orWhere('from_address', 'like', "%{$q}%")
                ->orWhere('body_text', 'like', "%{$q}%");
        });
    }

    /**
     * A missing emails_fts table or a MATCH expression FTS5 rejects used to
     * surface when the id list was resolved, which is what selected the LIKE
     * fallback. As a subquery it would instead blow up the whole page, so ask
     * the question up front. LIMIT 1 keeps it cheap regardless of hit count.
     */
    protected function ftsIsUsable(string $match): bool
    {
        try {
            DB::table('emails_fts')
                ->select('rowid')
                ->whereRaw('emails_fts MATCH ?', [$match])
                ->limit(1)
                ->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
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
