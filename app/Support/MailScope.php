<?php

namespace App\Support;

use App\Models\SavedSearch;
use Illuminate\Http\Request;

/**
 * What the inbox is currently showing: which account, which folder, whether
 * it is the archived or starred cut, and what was searched for.
 *
 * Saving a search means saving one of these, and reopening it means handing
 * the same one back to the same query builder, so a saved view cannot show
 * something the live inbox would not (ZERO-120).
 */
final class MailScope
{
    public function __construct(
        public readonly ?int $accountId = null,
        public readonly string $folder = 'INBOX',
        public readonly bool $archived = false,
        public readonly bool $starred = false,
        public readonly ?string $query = null,
    ) {}

    /**
     * @param  array<int, string>  $availableFolders
     */
    public static function fromRequest(Request $request, array $availableFolders): self
    {
        $folder = $request->string('folder')->toString();

        return new self(
            accountId: $request->filled('account') ? $request->integer('account') : null,
            // An unknown folder falls back rather than filtering on a name
            // nothing carries, which is what lets a saved view survive the
            // folder it named being removed.
            folder: in_array($folder, $availableFolders, true) ? $folder : 'INBOX',
            archived: $request->boolean('archived'),
            starred: $request->boolean('starred'),
            query: $request->string('q')->toString() ?: null,
        );
    }

    public static function fromSavedSearch(SavedSearch $search): self
    {
        return new self(
            accountId: $search->mail_account_id,
            folder: $search->folder ?: 'INBOX',
            archived: $search->archived,
            starred: $search->starred,
            query: $search->query,
        );
    }

    /**
     * Whether the inbox is currently showing exactly this scope.
     *
     * Paging and the opened conversation are ignored: page two of a saved
     * view is still that saved view, and so is the same list with a thread
     * open beside it.
     */
    public function matchesRequest(Request $request): bool
    {
        $current = collect($request->query())
            ->except(['page', 'open'])
            ->map(fn ($value) => (string) (is_scalar($value) ? $value : ''))
            ->sortKeys()
            ->all();

        $mine = collect($this->toQueryParams())
            ->map(fn ($value) => (string) $value)
            ->sortKeys()
            ->all();

        return $current === $mine;
    }

    /**
     * The querystring that reproduces this scope on the inbox route. Only what
     * differs from the default is included, so a plain saved search stays a
     * readable URL.
     *
     * @return array<string, int|string>
     */
    public function toQueryParams(): array
    {
        return array_filter([
            'q' => $this->query,
            'account' => $this->accountId,
            'folder' => $this->folder === 'INBOX' ? null : $this->folder,
            'archived' => $this->archived ? 1 : null,
            'starred' => $this->starred ? 1 : null,
        ], fn ($value) => $value !== null);
    }
}
