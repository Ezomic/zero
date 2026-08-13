<?php

namespace App\Actions\Mail;

use App\Models\Email;
use App\Models\User;
use App\Support\MailScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of "which of this user's mail matches this scope".
 *
 * It was previously inline in InboxController::listData(), which was fine
 * while the inbox was the only caller. A saved view's unread count is a second
 * caller, and a count computed from its own copy of the filters is a count
 * that drifts away from the list it labels (ZERO-120).
 */
class BuildScopedEmailQuery
{
    /**
     * @return Builder<Email>
     */
    public function handle(User $user, MailScope $scope): Builder
    {
        $base = Email::query()
            ->whereIn('mail_account_id', $user->mailAccounts()->select('id'))
            ->where('is_deleted', false);

        if ($scope->starred) {
            // Starred cuts across folders the way archived does, so it does
            // not narrow to one (ZERO-113).
            $base->where('is_starred', true);
        } elseif ($scope->archived) {
            $base->where('is_archived', true);
        } else {
            $base->where('folder', $scope->folder)->where('is_archived', false);
        }

        if ($scope->accountId) {
            // Deliberately not checked against the user's accounts first: the
            // whereIn above already contains it, so a saved view naming an
            // account that has since been deleted returns nothing rather than
            // quietly widening to every account.
            $base->where('mail_account_id', $scope->accountId);
        }

        if ($scope->query) {
            $this->applySearch($base, $scope->query);
        }

        return $base;
    }

    /**
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
}
