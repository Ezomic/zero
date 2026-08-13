@php
    use App\Models\Email;
    use App\Models\MailFolder;
    use App\Services\Mail\SavedSearchCounts;
    use App\Support\MailScope;

    $navUser = auth()->user();
    $navAccounts = $navUser->mailAccounts()->with('folders')->get();
    $draftsCount = $navUser->drafts()->count();

    $selectedAccountId = request()->integer('account');
    $selectedFolder = request()->get('folder', 'INBOX');
    $selectedAccount = $selectedAccountId ? $navAccounts->firstWhere('id', $selectedAccountId) : null;

    // Unread per folder for the selected account (plus the unified inbox count
    // for the smart view) — one grouped query, no N+1.
    $unreadByFolder = Email::query()
        ->whereIn('mail_account_id', $navAccounts->pluck('id'))
        ->where('is_read', false)
        ->where('is_archived', false)
        ->where('is_deleted', false)
        ->selectRaw('mail_account_id, folder, COUNT(*) as c')
        ->groupBy('mail_account_id', 'folder')
        ->get()
        ->groupBy('mail_account_id')
        ->map(fn ($rows) => $rows->pluck('c', 'folder'));

    $inboxUnread = ($navAccounts->pluck('id')->flatMap(fn ($id) => [$unreadByFolder[$id]['INBOX'] ?? 0])->sum());
    $canonicalOrder = ['INBOX', 'SENT', 'DRAFTS', 'TRASH'];

    $isInbox = request()->routeIs('inbox.index', 'inbox.show') && ! request()->boolean('archived') && request()->get('folder', 'INBOX') === 'INBOX' && ! request()->filled('account');
    $isSent = request()->routeIs('inbox.index') && request()->get('folder') === 'SENT' && ! request()->filled('account');
    $isTrash = request()->routeIs('inbox.index') && request()->get('folder') === 'TRASH' && ! request()->filled('account');
    $isArchived = request()->routeIs('inbox.index') && request()->boolean('archived');
    $isStarred = request()->routeIs('inbox.index') && request()->boolean('starred');

    // One indexed count for the whole rail, in the same spirit as the grouped
    // unread query above: no per-account or per-view query (ZERO-113).
    $starredCount = Email::query()
        ->whereIn('mail_account_id', $navAccounts->pluck('id'))
        ->where('is_starred', true)
        ->where('is_deleted', false)
        ->count();

    // Unlike the counts above, one of these is a full FTS query rather than an
    // indexed column filter, so they come back as one cached map instead of a
    // search per saved view on every render (ZERO-120).
    $savedSearches = $navUser->savedSearches;
    $savedCounts = app(SavedSearchCounts::class)->forUser($navUser, $savedSearches);
    $activeSavedId = request()->routeIs('inbox.index')
        ? $savedSearches->first(fn ($s) => MailScope::fromSavedSearch($s)->matchesRequest(request()))?->id
        : null;
@endphp

<div class="rail-scroll">
    <div class="nav-label">Views</div>
    <div class="nav-section">
        <a href="{{ route('inbox.index') }}" class="nav-item {{ $isInbox ? 'active' : '' }}">
            <svg class="ic"><use href="#i-inbox"/></svg>Unified inbox
            @if ($inboxUnread > 0)<span class="count">{{ $inboxUnread > 99 ? '99+' : $inboxUnread }}</span>@endif
        </a>
        <a href="{{ route('inbox.index', ['folder' => 'SENT']) }}" class="nav-item {{ $isSent ? 'active' : '' }}">
            <svg class="ic"><use href="#i-sent"/></svg>Sent
        </a>
        <a href="{{ route('drafts.index') }}" class="nav-item {{ request()->routeIs('drafts.index') ? 'active' : '' }}">
            <svg class="ic"><use href="#i-draft"/></svg>Drafts
            @if ($draftsCount > 0)<span class="count">{{ $draftsCount }}</span>@endif
        </a>
        <a href="{{ route('inbox.index', ['folder' => 'TRASH']) }}" class="nav-item {{ $isTrash ? 'active' : '' }}">
            <svg class="ic"><use href="#i-trash"/></svg>Trash
        </a>
        <a href="{{ route('inbox.index', ['starred' => 1]) }}" class="nav-item {{ $isStarred ? 'active' : '' }}">
            <svg class="ic"><use href="#i-star"/></svg>Starred
            @if ($starredCount > 0)<span class="count">{{ $starredCount > 99 ? '99+' : $starredCount }}</span>@endif
        </a>
        <a href="{{ route('inbox.index', ['archived' => 1]) }}" class="nav-item {{ $isArchived ? 'active' : '' }}">
            <svg class="ic"><use href="#i-archive"/></svg>Archived
        </a>
        <a href="{{ route('attachments.index') }}" class="nav-item {{ request()->routeIs('attachments.index') ? 'active' : '' }}">
            <svg class="ic"><use href="#i-clip"/></svg>Attachments
        </a>
    </div>

    @if ($savedSearches->isNotEmpty())
        <div class="nav-label saved-label">
            Saved
            <a href="{{ route('savedSearches.index') }}" title="Manage saved searches">Manage</a>
        </div>
        <div class="nav-section">
            @foreach ($savedSearches as $saved)
                @php $savedMissing = $saved->accountIsMissing($navUser); @endphp
                <a href="{{ route('inbox.index', MailScope::fromSavedSearch($saved)->toQueryParams()) }}"
                   class="nav-item {{ $activeSavedId === $saved->id ? 'active' : '' }}"
                   title="{{ $savedMissing ? 'The account this view was saved against no longer exists' : $saved->query }}">
                    <svg class="ic"><use href="#i-search"/></svg>{{ $saved->name }}
                    @if ($savedMissing)
                        <span class="count saved-gone" title="Account removed">!</span>
                    @elseif (($savedCounts[$saved->id] ?? 0) > 0)
                        <span class="count">{{ $savedCounts[$saved->id] > 99 ? '99+' : $savedCounts[$saved->id] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    {{-- Folders of the account picked in the top selector. No account picked =
         unified view, so only the smart views above are shown. --}}
    @if ($selectedAccount)
        @php
            $folders = $selectedAccount->folders
                ->sort(function ($a, $b) use ($canonicalOrder) {
                    $ai = array_search($a->local_name, $canonicalOrder, true);
                    $bi = array_search($b->local_name, $canonicalOrder, true);

                    return match (true) {
                        $ai !== false && $bi !== false => $ai <=> $bi,
                        $ai !== false => -1,
                        $bi !== false => 1,
                        default => strcasecmp($a->local_name, $b->local_name),
                    };
                })
                ->values();
        @endphp

        <div class="rail-accounts" x-data="{
            filter: '',
            matches(name) { return ! this.filter.length || name.toLowerCase().includes(this.filter.toLowerCase()) }
        }">
            <div class="nav-label rail-acct-label">
                <span class="acct-dot" style="background:{{ $selectedAccount->color }}"></span>
                <span class="acct-em">{{ $selectedAccount->display_name ?: $selectedAccount->email_address }}</span>
            </div>

            @if ($folders->count() > 6)
                <div class="rail-filter">
                    <svg class="ic-sm"><use href="#i-search"/></svg>
                    <input type="text" placeholder="Filter folders&hellip;" x-model="filter" aria-label="Filter folders">
                </div>
            @endif

            <div class="folders folders-flat">
                @forelse ($folders as $folder)
                    @php
                        $label = MailFolder::displayName($folder->local_name);
                        $depth = min(substr_count($folder->local_name, '/'), 2);
                        $unread = $unreadByFolder[$selectedAccount->id][$folder->local_name] ?? 0;
                        $canonicalIcon = [
                            'INBOX' => '#i-inbox',
                            'SENT' => '#i-sent',
                            'DRAFTS' => '#i-draft',
                            'TRASH' => '#i-trash',
                        ][$folder->local_name] ?? null;
                        $active = request()->routeIs('inbox.index') && $selectedFolder === $folder->local_name;
                    @endphp
                    <a href="{{ route('inbox.index', ['account' => $selectedAccount->id, 'folder' => $folder->local_name]) }}"
                       class="folder-item depth-{{ $depth }} {{ $active ? 'active' : '' }}"
                       x-show="matches(@js($label))">
                        @if ($canonicalIcon)
                            <svg class="fico"><use href="{{ $canonicalIcon }}"/></svg>
                        @else
                            <svg class="fico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h6l2 2h10v9H3z"/></svg>
                        @endif
                        <span class="n">{{ $label }}</span>
                        @if ($unread > 0)
                            <span class="unreadct">{{ $unread > 99 ? '99+' : $unread }}</span>
                        @endif
                    </a>
                @empty
                    <div class="folder-empty">No folders synced yet</div>
                @endforelse
            </div>
        </div>
    @endif
</div>
