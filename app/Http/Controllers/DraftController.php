<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\Draft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DraftController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(): View
    {
        $drafts = $this->currentUser()->drafts()->latest('updated_at')->get();

        return view('inbox.drafts', compact('drafts'));
    }

    /**
     * Upserts the in-progress compose form. Called periodically by the
     * compose page's autosave JS; returns the draft id so subsequent saves
     * update the same row instead of creating a new one each time.
     */
    public function autosave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'draft_id' => ['nullable', 'integer'],
            'mail_account_id' => ['nullable', 'exists:mail_accounts,id'],
            'to' => ['nullable', 'string'],
            'cc' => ['nullable', 'string'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'in_reply_to' => ['nullable', 'string'],
            'references' => ['nullable', 'string'],
        ]);

        if (empty($data['to']) && empty($data['subject']) && empty($data['body'])) {
            return response()->json(['draft_id' => $data['draft_id'] ?? null]);
        }

        $draft = null;

        if (! empty($data['draft_id'])) {
            $draft = Draft::where('id', $data['draft_id'])->where('user_id', auth()->id())->first();
        }

        if (! $draft) {
            $draft = new Draft(['user_id' => auth()->id()]);
        }

        $draft->fill([
            'mail_account_id' => $data['mail_account_id'] ?? null,
            'to_addresses' => $data['to'] ?? null,
            'cc_addresses' => $data['cc'] ?? null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'references_header' => $data['references'] ?? null,
        ])->save();

        return response()->json(['draft_id' => $draft->id]);
    }

    public function destroy(Draft $draft): RedirectResponse
    {
        abort_unless($draft->user_id === auth()->id(), 403);
        $draft->delete();

        return redirect()->route('drafts.index')->with('status', 'Draft discarded.');
    }
}
