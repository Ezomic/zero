<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\ArchiveRule;
use App\Models\Email;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rules that archive known noise on arrival, and the log that lets each
 * message they filed be put back (ZERO-127).
 */
class ArchiveRuleController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(): View
    {
        $user = $this->currentUser();

        return view('inbox.archive-rules', [
            'user' => $user,
            'rules' => ArchiveRule::where('user_id', $user->id)->orderBy('kind')->orderBy('value')->get(),
            'filed' => Email::query()
                ->whereIn('mail_account_id', $user->mailAccounts()->pluck('id'))
                ->whereNotNull('archived_by_rule_id')
                ->where('is_archived', true)
                ->where('is_deleted', false)
                ->latest('sent_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->currentUser();

        $request->validate([
            'kind' => ['required', Rule::in([ArchiveRule::ADDRESS, ArchiveRule::DOMAIN])],
            'value' => ['required', 'string', 'max:255'],
            'mail_account_id' => ['nullable', 'integer', Rule::in($user->mailAccounts()->pluck('id')->all())],
        ]);

        $kind = $request->string('kind')->toString();
        $value = strtolower(trim($request->string('value')->toString()));

        if ($kind === ArchiveRule::ADDRESS && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'That is not an email address.');
        }

        if ($kind === ArchiveRule::DOMAIN && ! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $value)) {
            return back()->with('error', 'That is not a domain.');
        }

        ArchiveRule::firstOrCreate([
            'user_id' => $user->id,
            'mail_account_id' => $request->filled('mail_account_id') ? $request->integer('mail_account_id') : null,
            'kind' => $kind,
            'value' => $value,
        ]);

        return back()->with('status', "New mail from {$value} will be archived.");
    }

    public function destroy(ArchiveRule $rule): RedirectResponse
    {
        abort_unless($rule->user_id === $this->currentUser()->id, 404);

        $rule->delete();

        return back()->with('status', 'Rule removed. Mail it already filed stays archived.');
    }

    public function undo(Email $email): RedirectResponse
    {
        abort_unless($this->currentUser()->mailAccounts()->whereKey($email->mail_account_id)->exists(), 404);

        $email->update(['is_archived' => false, 'archived_by_rule_id' => null]);

        return back()->with('status', 'Moved back to the inbox.');
    }
}
