<?php

namespace App\Actions\Mail;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\MailFolder;
use App\Models\MailRule;
use App\Support\MailRules\MessageFacts;
use App\Support\MailRules\RetroWindow;
use App\Support\MailRules\RuleMatcher;
use App\Support\MailRules\RuleOutcome;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies one rule to mail that is already in the inbox, a bounded stretch at
 * a time.
 *
 * A mailbox can hold far more messages than a request or the sync budget
 * should spend on one click (ZERO-91), so each call looks at no more than
 * $scanLimit candidates, newest first, and hands back a cursor for the next
 * stretch. Only messages the rule would still change are candidates, which is
 * what makes running it twice harmless. The preview takes exactly the same
 * window as the apply, so its count is the number the apply will act on.
 */
class ApplyRuleToExistingMail
{
    public const DEFAULT_SCAN_LIMIT = 1000;

    public function __construct(
        protected ApplyRuleOutcome $applyOutcome,
        protected int $scanLimit = self::DEFAULT_SCAN_LIMIT,
    ) {}

    public function preview(MailRule $rule, ?int $before): RetroWindow
    {
        return $this->run($rule, $before, apply: false);
    }

    public function apply(MailRule $rule, ?int $before): RetroWindow
    {
        return $this->run($rule, $before, apply: true);
    }

    protected function run(MailRule $rule, ?int $before, bool $apply): RetroWindow
    {
        $accountIds = $this->accountIds($rule);

        if ($accountIds === []) {
            return new RetroWindow(0, 0, null, null);
        }

        $candidates = $this->candidates($rule, $accountIds, $before)->limit($this->scanLimit + 1)->get();
        $more = $candidates->count() > $this->scanLimit;
        $window = $candidates->take($this->scanLimit);

        $matches = $window->filter(fn (Email $email): bool => RuleMatcher::matches($rule, $this->factsFor($email)))->values();

        if ($apply) {
            $this->actOn($rule, $accountIds, $matches);
        }

        $lastId = $window->last()?->id;
        $dates = $window->pluck('sent_at')->filter();
        $oldest = $dates->isEmpty() ? null : $dates->min();

        return new RetroWindow(
            scanned: $window->count(),
            matched: $matches->count(),
            nextBefore: $more && $lastId !== null ? $lastId : null,
            oldest: $oldest instanceof CarbonInterface ? $oldest : null,
        );
    }

    /**
     * The rule's own user's accounts only, narrowed to the one it names. A
     * file rule is further narrowed to accounts that really have the folder,
     * and never to a folder rules are barred from.
     *
     * @return list<int>
     */
    protected function accountIds(MailRule $rule): array
    {
        $ids = MailAccount::query()
            ->where('user_id', $rule->user_id)
            ->when($rule->mail_account_id !== null, fn (Builder $query) => $query->whereKey($rule->mail_account_id))
            ->get(['id'])
            ->map(fn (MailAccount $account): int => $account->id)
            ->all();

        $ids = array_values($ids);

        if ($rule->action !== MailRule::FILE) {
            return $ids;
        }

        if ($rule->folder === null || in_array(strtoupper($rule->folder), MailRule::UNFILEABLE, true)) {
            return [];
        }

        return array_values(MailFolder::query()
            ->whereIn('mail_account_id', $ids)
            ->where('local_name', $rule->folder)
            ->get(['mail_account_id'])
            ->map(fn (MailFolder $folder): int => $folder->mail_account_id)
            ->all());
    }

    /**
     * @param  list<int>  $accountIds
     * @return Builder<Email>
     */
    protected function candidates(MailRule $rule, array $accountIds, ?int $before): Builder
    {
        return Email::query()
            ->select(['id', 'mail_account_id', 'from_address', 'from_name', 'to_addresses', 'cc_addresses', 'subject', 'uid', 'folder', 'remote_folder_path', 'is_read', 'is_starred', 'is_archived', 'sent_at'])
            ->whereIn('mail_account_id', $accountIds)
            ->where('folder', 'INBOX')
            ->where('is_deleted', false)
            ->where('is_archived', false)
            ->when($before !== null, fn (Builder $query) => $query->where('id', '<', $before))
            ->when($rule->action === MailRule::STAR, fn (Builder $query) => $query->where('is_starred', false))
            ->when($rule->action === MailRule::MARK_READ, fn (Builder $query) => $query->where('is_read', false))
            ->when($rule->action === MailRule::FILE, fn (Builder $query) => $query->whereNotNull('uid')->where('uid', '!=', ''))
            ->orderByDesc('id');
    }

    protected function factsFor(Email $email): MessageFacts
    {
        $recipients = [];

        foreach ([$email->to_addresses, $email->cc_addresses] as $list) {
            foreach (is_array($list) ? $list : [] as $recipient) {
                if (is_string($recipient)) {
                    $recipients[] = $recipient;
                }
            }
        }

        return new MessageFacts($email->from_address, $email->from_name, $recipients, (string) $email->subject);
    }

    /**
     * @param  list<int>  $accountIds
     * @param  Collection<int, Email>  $matches
     */
    protected function actOn(MailRule $rule, array $accountIds, Collection $matches): void
    {
        $outcome = new RuleOutcome(
            archivedByRuleId: $rule->action === MailRule::ARCHIVE ? $rule->id : null,
            star: $rule->action === MailRule::STAR,
            markRead: $rule->action === MailRule::MARK_READ,
            folder: $rule->action === MailRule::FILE ? $rule->folder : null,
            matchedRuleIds: [$rule->id],
        );

        try {
            DB::transaction(function () use ($rule, $accountIds, $matches, $outcome): void {
                // Read again inside the transaction: a second click on Apply, or a
                // sync, may have dealt with some of these already, and a stale row
                // would queue a second move for a uid that has already left.
                $current = $this->candidates($rule, $accountIds, null)->whereKey($matches->pluck('id')->all())->get();

                foreach ($current as $email) {
                    $wasStarred = $email->is_starred;
                    $wasRead = $email->is_read;

                    $email->update(array_filter([
                        'is_archived' => $outcome->archivedByRuleId !== null ? true : null,
                        'archived_by_rule_id' => $outcome->archivedByRuleId,
                        'is_starred' => $outcome->star ? true : null,
                        'is_read' => $outcome->markRead ? true : null,
                    ], fn (mixed $value): bool => $value !== null));

                    $this->applyOutcome->handle($email, $outcome, $wasStarred, $wasRead);
                }
            });
        } finally {
            foreach (MailAccount::query()->whereIn('id', $accountIds)->get() as $account) {
                ApplyRuleOutcome::dispatchQueued($account);
            }
        }
    }
}
