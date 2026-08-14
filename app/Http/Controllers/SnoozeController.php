<?php

namespace App\Http\Controllers;

use App\Actions\Mail\SnoozeThread;
use App\Concerns\InteractsWithCurrentUser;
use App\Models\Email;
use App\Support\AppTimezone;
use App\Support\SnoozePresets;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hide a conversation now, put it back at a time I choose.
 *
 * Triage could only delete, file or skip, and skip is session scoped and
 * forgotten on the next visit, so anything that could not be dealt with today
 * had to stay in the inbox looking unread (ZERO-114).
 */
class SnoozeController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(
        protected SnoozeThread $snooze,
    ) {}

    /**
     * Everything currently put off, so nothing is invisible.
     */
    public function index(): View
    {
        $user = $this->currentUser();

        $emails = Email::query()
            ->whereIn('mail_account_id', $user->mailAccounts()->select('id'))
            ->where('is_deleted', false)
            ->snoozed()
            // One row per conversation, the same collapse the inbox does.
            ->whereIn('id', Email::query()
                ->whereIn('mail_account_id', $user->mailAccounts()->select('id'))
                ->where('is_deleted', false)
                ->snoozed()
                ->selectRaw('MAX(id) as id')
                ->groupBy('thread_id'))
            ->with('mailAccount')
            ->orderBy('snoozed_until')
            ->paginate(25);

        return view('inbox.snoozed', ['emails' => $emails]);
    }

    public function store(Request $request, Email $email): RedirectResponse
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        $until = $this->resolveTime($request);

        if (! $until instanceof CarbonImmutable) {
            return back()->with('error', 'Pick a time in the future to snooze until.');
        }

        $this->snooze->handle($email, $until);

        return redirect()
            ->route('inbox.index')
            ->with('status', 'Snoozed until '.$until->timezone(AppTimezone::name())->format('D j M, H:i').'.');
    }

    public function destroy(Email $email): RedirectResponse
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        $this->snooze->wake($email);

        return back()->with('status', 'Back in the inbox.');
    }

    /**
     * Either one of the presets or a specific time typed in. Anything in the
     * past is refused rather than silently snapped forward: a snooze that
     * expires the moment it is set looks like the button did nothing.
     */
    protected function resolveTime(Request $request): ?CarbonImmutable
    {
        $preset = $request->string('preset')->toString();

        if (SnoozePresets::isKnown($preset)) {
            return SnoozePresets::resolve($preset);
        }

        $at = $request->string('until')->toString();

        if ($at === '') {
            return null;
        }

        try {
            $until = CarbonImmutable::parse($at, AppTimezone::name());
        } catch (\Throwable) {
            return null;
        }

        return $until->isFuture() ? $until : null;
    }
}
