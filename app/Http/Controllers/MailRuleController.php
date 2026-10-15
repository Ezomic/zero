<?php

namespace App\Http\Controllers;

use App\Actions\Mail\ApplyRuleToExistingMail;
use App\Concerns\InteractsWithCurrentUser;
use App\Models\Email;
use App\Models\MailFolder;
use App\Models\MailRule;
use App\Support\MailRules\RuleMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;
use Illuminate\View\View;

/**
 * Rules that file, archive, star or mark mail as it arrives, the order they
 * run in, and the log that lets each archived message be put back (ZERO-118,
 * ZERO-127).
 */
class MailRuleController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(): View
    {
        $user = $this->currentUser();
        $accountIds = $user->mailAccounts()->pluck('id');

        return view('inbox.mail-rules', [
            'user' => $user,
            'rules' => MailRule::where('user_id', $user->id)->orderBy('position')->orderBy('id')->get(),
            'folders' => MailFolder::query()
                ->whereIn('mail_account_id', $accountIds)
                ->whereNotIn('local_name', MailRule::UNFILEABLE)
                ->orderBy('local_name')
                ->get(['mail_account_id', 'local_name'])
                ->groupBy('mail_account_id')
                ->map(fn ($rows) => $rows->pluck('local_name')->values()->all())
                ->all(),
            'filed' => Email::query()
                ->whereIn('mail_account_id', $accountIds)
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
        $ownAccounts = $user->mailAccounts()->pluck('id')->all();

        $validator = Validator::make($request->all(), [
            'field' => ['required', Rule::in(MailRule::FIELDS)],
            'operator' => ['required', Rule::in(MailRule::OPERATORS)],
            'value' => ['required', 'string', 'max:'.MailRule::MAX_PATTERN],
            'action' => ['required', Rule::in(MailRule::ACTIONS)],
            'mail_account_id' => ['nullable', 'integer', Rule::in($ownAccounts)],
            'folder' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function (ValidationValidator $validator) use ($request): void {
            $value = RuleMatcher::clean($request->string('value')->toString());

            if ($value === '') {
                $validator->errors()->add('value', 'Enter something to match.');
            }

            if ($request->string('field')->toString() === MailRule::SUBJECT && $request->string('operator')->toString() === MailRule::DOMAIN) {
                $validator->errors()->add('operator', 'A subject has no domain.');
            }

            if ($request->string('action')->toString() !== MailRule::FILE || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $request->filled('mail_account_id')) {
                $validator->errors()->add('mail_account_id', 'Pick the account whose folder this files into.');

                return;
            }

            $folder = $request->string('folder')->toString();

            $exists = MailFolder::query()
                ->where('mail_account_id', $request->integer('mail_account_id'))
                ->where('local_name', $folder)
                ->exists();

            if (! $exists || in_array(strtoupper($folder), MailRule::UNFILEABLE, true)) {
                $validator->errors()->add('folder', 'Pick a folder on that account.');
            }
        });

        $validator->validate();

        $field = $request->string('field')->toString();
        $operator = $request->string('operator')->toString();
        $action = $request->string('action')->toString();
        $value = mb_strtolower(RuleMatcher::clean($request->string('value')->toString()));

        if ($operator === MailRule::IS && $field !== MailRule::SUBJECT && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return back()->withInput()->with('error', 'That is not an email address.');
        }

        if ($operator === MailRule::DOMAIN && ! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $value)) {
            return back()->withInput()->with('error', 'That is not a domain.');
        }

        MailRule::create([
            'user_id' => $user->id,
            'mail_account_id' => $request->filled('mail_account_id') ? $request->integer('mail_account_id') : null,
            'position' => (MailRule::where('user_id', $user->id)->orderByDesc('position')->first()->position ?? 0) + 1,
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'action' => $action,
            'folder' => $action === MailRule::FILE ? $request->string('folder')->toString() : null,
            'stop_on_match' => $request->boolean('stop_on_match'),
        ]);

        return back()->with('status', 'Rule added. It applies to mail that arrives from now on.');
    }

    public function move(Request $request, MailRule $rule): RedirectResponse
    {
        $this->authorizeRule($rule);

        $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $direction = $request->string('direction')->toString();
        $ids = $this->orderedIds($rule->user_id);
        $index = array_search($rule->id, $ids, true);

        if ($index !== false) {
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if (isset($ids[$swapWith])) {
                [$ids[$index], $ids[$swapWith]] = [$ids[$swapWith], $ids[$index]];
            }
        }

        $this->renumber($rule->user_id, array_values($ids));

        return back();
    }

    public function destroy(MailRule $rule): RedirectResponse
    {
        $this->authorizeRule($rule);

        $rule->delete();

        $this->renumber($rule->user_id, $this->orderedIds($rule->user_id));

        return back()->with('status', 'Rule removed. Mail it already handled stays as it is.');
    }

    public function preview(Request $request, MailRule $rule, ApplyRuleToExistingMail $existing): View
    {
        $this->authorizeRule($rule);

        $before = $this->cursor($request);

        return view('inbox.mail-rule-apply', [
            'rule' => $rule,
            'before' => $before,
            'window' => $existing->preview($rule, $before),
        ]);
    }

    public function apply(Request $request, MailRule $rule, ApplyRuleToExistingMail $existing): RedirectResponse
    {
        $this->authorizeRule($rule);

        $window = $existing->apply($rule, $this->cursor($request));

        $noun = $window->matched === 1 ? 'message' : 'messages';
        $status = "Applied to {$window->matched} {$noun}.";

        if ($window->nextBefore !== null) {
            return redirect()->route('mailRules.preview', [$rule, 'before' => $window->nextBefore])
                ->with('status', "{$status} Older mail is still to check.");
        }

        return redirect()->route('mailRules.index')->with('status', "{$status} That was all of it.");
    }

    public function undo(Email $email): RedirectResponse
    {
        abort_unless($this->currentUser()->mailAccounts()->whereKey($email->mail_account_id)->exists(), 404);

        abort_if($email->archived_by_rule_id === null, 404);

        $email->update(['is_archived' => false, 'archived_by_rule_id' => null]);

        return back()->with('status', 'Moved back to the inbox.');
    }

    protected function authorizeRule(MailRule $rule): void
    {
        abort_unless($rule->user_id === $this->currentUser()->id, 404);
    }

    protected function cursor(Request $request): ?int
    {
        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return $request->filled('before') ? $request->integer('before') : null;
    }

    /** @return list<int> */
    protected function orderedIds(int $userId): array
    {
        return array_values(MailRule::where('user_id', $userId)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (MailRule $rule): int => $rule->id)
            ->all());
    }

    /** @param list<int> $orderedIds */
    protected function renumber(int $userId, array $orderedIds): void
    {
        DB::transaction(function () use ($userId, $orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                MailRule::where('user_id', $userId)->whereKey($id)->where('position', '!=', $index + 1)->update(['position' => $index + 1]);
            }
        });
    }
}
