<?php

namespace App\Services\Mail;

use App\Actions\Mail\BuildScopedEmailQuery;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\MailScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Unread counts for a user's saved views.
 *
 * The counts sit next to the folder counts in the rail, but they are not the
 * same kind of thing: a folder count is an indexed column filter, while a
 * saved view's count is a full FTS query. Rendering the rail must not cost one
 * search per saved view on every page load, so the whole map is cached under a
 * single key and read back in one go (ZERO-120).
 *
 * The key carries the newest updated_at and the row count, so editing,
 * adding or removing a view invalidates it immediately. Only new mail arriving
 * has to wait out the TTL, and a slightly stale unread badge is the same
 * bargain the sidebar's polled badge already makes.
 */
class SavedSearchCounts
{
    public const TTL_SECONDS = 60;

    public function __construct(
        protected BuildScopedEmailQuery $scopedQuery,
    ) {}

    /**
     * @param  Collection<int, SavedSearch>  $searches
     * @return array<int, int> saved search id => unread count
     */
    public function forUser(User $user, Collection $searches): array
    {
        if ($searches->isEmpty()) {
            return [];
        }

        return Cache::remember(
            $this->cacheKey($user, $searches),
            self::TTL_SECONDS,
            fn () => $this->compute($user, $searches),
        );
    }

    /**
     * @param  Collection<int, SavedSearch>  $searches
     * @return array<int, int>
     */
    protected function compute(User $user, Collection $searches): array
    {
        $counts = [];

        foreach ($searches as $search) {
            $counts[$search->id] = $this->scopedQuery
                ->handle($user, MailScope::fromSavedSearch($search))
                ->where('is_read', false)
                ->count();
        }

        return $counts;
    }

    /**
     * @param  Collection<int, SavedSearch>  $searches
     */
    protected function cacheKey(User $user, Collection $searches): string
    {
        $newest = $searches
            ->map(fn (SavedSearch $search) => $search->updated_at?->getTimestamp() ?? 0)
            ->max();

        return sprintf(
            'saved-search-counts:%d:%d:%d',
            $user->id,
            $searches->count(),
            is_numeric($newest) ? (int) $newest : 0,
        );
    }
}
