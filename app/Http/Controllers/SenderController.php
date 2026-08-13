<?php

namespace App\Http\Controllers;

use App\Actions\Mail\QueueMirrorAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Models\Email;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Everything from one sender, and the ability to act on all of it at once.
 *
 * The useful question about a newsletter or a noisy service is almost always
 * about all of its mail rather than the one message in front of you, and that
 * was a search away at best (ZERO-122).
 */
class SenderController extends Controller
{
    use InteractsWithCurrentUser;

    /** Beyond this, a destructive action asks first. */
    protected const CONFIRM_ABOVE = 25;

    /** Rows per UPDATE, so acting on a large sender cannot exceed SQLite's
     *  bind limit the way ZERO-91's id lists did. */
    protected const CHUNK = 500;

    public function __construct(
        protected QueueMirrorAction $queueMirror,
    ) {}

    public function show(Request $request): View
    {
        $address = $this->address($request);

        // toBase() so this is a plain aggregate row rather than a half-built
        // Email model carrying columns the model does not declare.
        $summary = (clone $this->mailFrom($address))
            ->toBase()
            ->selectRaw('count(*) as total, min(sent_at) as first_seen, max(sent_at) as last_seen')
            ->first();

        $total = is_object($summary) && is_numeric($summary->total ?? null) ? (int) $summary->total : 0;
        $firstSeen = is_object($summary) && is_string($summary->first_seen ?? null) ? $summary->first_seen : null;
        $lastSeen = is_object($summary) && is_string($summary->last_seen ?? null) ? $summary->last_seen : null;

        return view('inbox.sender', [
            'address' => $address,
            'total' => $total,
            'firstSeen' => $firstSeen,
            'lastSeen' => $lastSeen,
            'unread' => (clone $this->mailFrom($address))->where('is_read', false)->count(),
            'emails' => $this->mailFrom($address)->with('mailAccount')->latest('sent_at')->paginate(25)->withQueryString(),
            'confirmAbove' => self::CONFIRM_ABOVE,
        ]);
    }

    /**
     * Applies an action to every message from the sender, not just the page
     * being looked at, which is the entire point of the view.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $request->validate([
            'address' => ['required', 'string'],
            'action' => ['required', 'in:archive,read,delete'],
            'confirmed' => ['nullable'],
        ]);

        $address = $this->address($request);
        $action = $request->string('action')->toString();
        $total = (clone $this->mailFrom($address))->count();

        if ($total === 0) {
            return back()->with('status', 'Nothing from that sender.');
        }

        // Deleting someone's entire history on one click is the kind of thing
        // that should be asked about rather than discovered afterwards.
        if ($action === 'delete' && $total > self::CONFIRM_ABOVE && ! $request->boolean('confirmed')) {
            return back()->with('error', "That would delete {$total} messages from {$address}. Tick the confirmation to go ahead.");
        }

        $touched = $action === 'archive'
            ? $this->archiveAll($address)
            : $this->applyPerMessage($address, $action);

        return back()->with('status', "{$touched} message(s) from {$address} updated.");
    }

    /**
     * Archiving is local only, so it needs no mirror action and can go out as
     * chunked updates rather than one write per message.
     */
    protected function archiveAll(string $address): int
    {
        $touched = 0;

        (clone $this->mailFrom($address))->where('is_archived', false)
            ->select('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$touched): void {
                $ids = $rows->pluck('id')->all();
                Email::whereIn('id', $ids)->update(['is_archived' => true]);
                $touched += count($ids);
            });

        return $touched;
    }

    /**
     * Delete and mark-read each have to reach the server, and the mirror is
     * recorded per message, so these walk the set in chunks rather than
     * loading all of it.
     */
    protected function applyPerMessage(string $address, string $action): int
    {
        $touched = 0;

        $query = (clone $this->mailFrom($address));

        if ($action === 'read') {
            $query->where('is_read', false);
        }

        $query->chunkById(self::CHUNK, function ($messages) use ($action, &$touched): void {
            foreach ($messages as $message) {
                if ($action === 'delete') {
                    $message->update(['is_deleted' => true]);
                    $this->queueMirror->handle($message, 'delete');
                } else {
                    $message->update(['is_read' => true]);
                    $this->queueMirror->handle($message, 'mark_read');
                }

                $touched++;
            }
        });

        return $touched;
    }

    /** @return Builder<Email> */
    protected function mailFrom(string $address): Builder
    {
        return Email::query()
            ->whereIn('mail_account_id', $this->currentUser()->mailAccounts()->pluck('id'))
            ->where('is_deleted', false)
            ->whereRaw('lower(from_address) = ?', [$address]);
    }

    protected function address(Request $request): string
    {
        $address = trim(strtolower($request->string('address')->toString()));

        abort_if($address === '' || ! str_contains($address, '@'), 404);

        return $address;
    }
}
