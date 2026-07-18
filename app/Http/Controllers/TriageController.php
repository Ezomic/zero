<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyEmailFlagJob;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Services\Mail\GraphMailSyncService;
use App\Services\Mail\ImapSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A focused "get to inbox zero" flow: one conversation at a time from a
 * single account's Inbox, with exactly two ways to clear it — delete, or
 * mark read and move to a folder — plus a session-scoped skip for anything
 * not ready to decide on yet. Since delete/move both remove the thread from
 * the Inbox query, there's no separate "processed" tracker to maintain.
 */
class TriageController extends Controller
{
    public function index(Request $request, ImapSyncService $imapSyncService, GraphMailSyncService $graphMailSyncService): View
    {
        $accounts = auth()->user()->mailAccounts()->where('is_active', true)->get();
        /** @var MailAccount|null $account */
        $account = $accounts->firstWhere('id', $request->integer('account')) ?: $accounts->first();

        if (! $account) {
            return view('inbox.triage', [
                'accounts' => $accounts,
                'account' => null,
                'email' => null,
                'remaining' => 0,
                'skippedCount' => 0,
                'folders' => [],
            ]);
        }

        $skippedThreadIds = $request->session()->get("triage_skipped.{$account->id}", []);

        $base = fn () => Email::where('mail_account_id', $account->id)
            ->where('folder', 'INBOX')
            ->where('is_archived', false)
            ->where('is_deleted', false)
            ->whereNotIn('thread_id', $skippedThreadIds);

        $nextThreadId = (clone $base())->reorder()->oldest('sent_at')->value('thread_id');

        $email = null;
        $remaining = 0;
        $suggestedFolder = null;

        if ($nextThreadId) {
            $email = (clone $base())->where('thread_id', $nextThreadId)->latest('sent_at')->first();
            $remaining = (clone $base())->reorder()->distinct()->count('thread_id');

            if ($email && $email->body_html === null && $email->body_text === null) {
                try {
                    $syncService = $account->provider === MailAccount::PROVIDER_OUTLOOK ? $graphMailSyncService : $imapSyncService;
                    $syncService->fetchBody($email);
                    $email->refresh();
                } catch (\Throwable) {
                    // Leave it empty — the view falls back gracefully.
                }
            }
        }

        $folders = MailFolder::where('mail_account_id', $account->id)
            ->pluck('local_name')
            ->unique()
            ->reject(fn ($f) => $f === 'INBOX')
            ->values();

        if ($email) {
            $suggestedFolder = $email->suggestedFolder();

            // Only trust a suggestion that maps to a folder we actually know
            // about for this account.
            if ($suggestedFolder && ! $folders->contains($suggestedFolder)) {
                $suggestedFolder = null;
            }
        }

        return view('inbox.triage', [
            'accounts' => $accounts,
            'account' => $account,
            'email' => $email,
            'remaining' => $remaining,
            'skippedCount' => count($skippedThreadIds),
            'folders' => $folders,
            'suggestedFolder' => $suggestedFolder,
        ]);
    }

    public function delete(Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);

        foreach ($this->threadEmails($email)->get() as $message) {
            $message->update(['is_deleted' => true]);
            ApplyEmailFlagJob::dispatch($message, 'delete');
        }

        return redirect()->route('triage.index', ['account' => $email->mail_account_id]);
    }

    public function move(Request $request, Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);

        $data = $request->validate(['folder' => ['required', 'string']]);

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
            $message->update(['folder' => $data['folder'], 'uid' => null, 'is_read' => true]);
            ApplyEmailFlagJob::dispatch($message, 'move:'.$data['folder'], $sourceUid);
        }

        return redirect()->route('triage.index', ['account' => $email->mail_account_id]);
    }

    public function skip(Request $request, Email $email): RedirectResponse
    {
        $this->authorizeOwnership($email);

        $key = "triage_skipped.{$email->mail_account_id}";
        $skipped = $request->session()->get($key, []);
        $skipped[] = $email->thread_id;
        $request->session()->put($key, array_values(array_unique($skipped)));

        return redirect()->route('triage.index', ['account' => $email->mail_account_id]);
    }

    public function resetSkipped(Request $request): RedirectResponse
    {
        $accountId = $request->integer('account');
        $request->session()->forget("triage_skipped.{$accountId}");

        return redirect()->route('triage.index', ['account' => $accountId]);
    }

    protected function authorizeOwnership(Email $email): void
    {
        abort_unless($email->mailAccount->user_id === auth()->id(), 403);
    }

    /** @return Builder<Email> */
    protected function threadEmails(Email $email): Builder
    {
        return Email::where('mail_account_id', $email->mail_account_id)
            ->where('thread_id', $email->thread_id);
    }
}
