@php
    use App\Models\Email;
    use App\Models\MailFolder;

    $navAccounts = auth()->user()->mailAccounts()->with('folders')->get();
    $inboxUnread = $navAccounts->sum(fn ($a) => $a->unreadCount());
    $draftsCount = auth()->user()->drafts()->count();

    // One grouped query for every folder's unread count across all accounts,
    // keyed [account_id][folder] — avoids an N+1 over the folder tree.
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

    $canonicalOrder = ['INBOX', 'SENT', 'DRAFTS', 'TRASH'];

    $isInbox = request()->routeIs('inbox.index', 'inbox.show') && ! request()->boolean('archived') && request()->get('folder', 'INBOX') === 'INBOX' && ! request()->filled('account');
    $isSent = request()->routeIs('inbox.index') && request()->get('folder') === 'SENT' && ! request()->filled('account');
    $isTrash = request()->routeIs('inbox.index') && request()->get('folder') === 'TRASH' && ! request()->filled('account');
    $isArchived = request()->routeIs('inbox.index') && request()->boolean('archived');

    $selectedAccountId = request()->integer('account');
    $selectedFolder = request()->get('folder', 'INBOX');
    $defaultOpenId = $selectedAccountId ?: $navAccounts->first()?->id;
@endphp

<div class="brand">
    <a href="{{ route('inbox.index') }}" class="brand" style="padding:0;">
        <div class="brand-mark"><svg class="ic-sm" style="color:#fff"><use href="#i-inbox"/></svg></div>
        <div class="brand-name">Zero</div>
    </a>
</div>

<button type="button" class="nav-search" id="nav-search-btn">
    <svg class="ic-sm"><use href="#i-search"/></svg>
    <span>Search everything&hellip;</span>
    <kbd>/</kbd>
</button>

<div class="nav-section">
    <a href="{{ route('inbox.index') }}" class="nav-item {{ $isInbox ? 'active' : '' }}">
        <svg class="ic"><use href="#i-inbox"/></svg>Inbox
        <span id="unread-badge" class="count {{ $inboxUnread > 0 ? '' : 'hidden' }}">{{ $inboxUnread > 99 ? '99+' : $inboxUnread }}</span>
    </a>
    <a href="{{ route('inbox.index', ['folder' => 'SENT']) }}" class="nav-item {{ $isSent ? 'active' : '' }}">
        <svg class="ic"><use href="#i-sent"/></svg>Sent
    </a>
    <a href="{{ route('drafts.index') }}" class="nav-item {{ request()->routeIs('drafts.index') ? 'active' : '' }}">
        <svg class="ic"><use href="#i-draft"/></svg>Drafts
        @if ($draftsCount > 0)
            <span class="count">{{ $draftsCount }}</span>
        @endif
    </a>
    <a href="{{ route('inbox.index', ['folder' => 'TRASH']) }}" class="nav-item {{ $isTrash ? 'active' : '' }}">
        <svg class="ic"><use href="#i-trash"/></svg>Trash
    </a>
    <a href="{{ route('inbox.index', ['archived' => 1]) }}" class="nav-item {{ $isArchived ? 'active' : '' }}">
        <svg class="ic"><use href="#i-archive"/></svg>Archived
    </a>
    <a href="{{ route('triage.index') }}" class="nav-item warn {{ request()->routeIs('triage.index') ? 'active' : '' }}">
        <svg class="ic"><use href="#i-sparkle"/></svg>Process Inbox
        @if ($inboxUnread > 0)
            <span class="count">{{ $inboxUnread }}</span>
        @endif
    </a>
</div>

<button type="button" class="btn-compose" onclick="location.href='{{ route('compose.create') }}'">
    <svg class="ic-sm"><use href="#i-plus"/></svg>Compose
</button>

<div class="nav-section">
    <a href="{{ route('accounts.index') }}" class="nav-item {{ request()->routeIs('accounts.*') ? 'active' : '' }}">
        <svg class="ic"><use href="#i-users"/></svg>Accounts
    </a>
</div>

@if ($navAccounts->isNotEmpty())
    <div class="nav-label">Mailboxes</div>
    <div class="rail-accounts" x-data="{
        filter: '',
        open: @js([$defaultOpenId => true]),
        toggle(id) { this.open[id] = ! this.open[id] },
        isOpen(id) { return this.filter.length ? true : !! this.open[id] },
        matches(name) { return ! this.filter.length || name.toLowerCase().includes(this.filter.toLowerCase()) }
    }">
        <div class="rail-filter">
            <svg class="ic-sm"><use href="#i-search"/></svg>
            <input type="text" placeholder="Filter folders&hellip;" x-model="filter" aria-label="Filter folders">
        </div>

        @foreach ($navAccounts as $navAccount)
            @php
                $folders = $navAccount->folders
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
                $accountUnread = ($unreadByFolder[$navAccount->id] ?? collect())->sum();
            @endphp

            <div class="acct-group">
                <button type="button" class="acct-head" @click="toggle({{ $navAccount->id }})">
                    <svg class="acct-chev" :class="{ 'open': isOpen({{ $navAccount->id }}) }"><use href="#i-chev"/></svg>
                    <span class="acct-dot" style="background:{{ $navAccount->color }}"></span>
                    <span class="acct-em">{{ $navAccount->display_name ?: $navAccount->email_address }}</span>
                    @if ($accountUnread > 0)
                        <span class="acct-unread">{{ $accountUnread > 99 ? '99+' : $accountUnread }}</span>
                    @else
                        <span class="acct-badge">{{ $folders->count() }}</span>
                    @endif
                </button>

                <div class="folders" x-show="isOpen({{ $navAccount->id }})" x-cloak>
                    @forelse ($folders as $folder)
                        @php
                            $label = MailFolder::displayName($folder->local_name);
                            $depth = min(substr_count($folder->local_name, '/'), 2);
                            $unread = $unreadByFolder[$navAccount->id][$folder->local_name] ?? 0;
                            $canonicalIcon = [
                                'INBOX' => '#i-inbox',
                                'SENT' => '#i-sent',
                                'DRAFTS' => '#i-draft',
                                'TRASH' => '#i-trash',
                            ][$folder->local_name] ?? null;
                            $active = request()->routeIs('inbox.index')
                                && $selectedAccountId === $navAccount->id
                                && $selectedFolder === $folder->local_name;
                        @endphp
                        <a href="{{ route('inbox.index', ['account' => $navAccount->id, 'folder' => $folder->local_name]) }}"
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
        @endforeach
    </div>
@endif

<div class="navrail-foot">
    @include('partials.portal-switcher')

    <a href="{{ route('security.show') }}" class="nav-item {{ request()->routeIs('security.show') ? 'active' : '' }}">
        <svg class="ic"><use href="#i-check"/></svg>Security
    </a>
    <button type="button" class="nav-item" id="footThemeToggle">
        <svg class="ic" id="footThemeIcon"><use href="#i-moon"/></svg>Theme
    </button>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="nav-item" style="width:100%;">
            <svg class="ic"><use href="#i-x"/></svg>Log out
        </button>
    </form>
</div>

<script>
    document.getElementById('nav-search-btn')?.addEventListener('click', () => {
        const search = document.querySelector('input[name="q"]');
        if (search) {
            search.focus();
        } else {
            location.href = '{{ route('inbox.index') }}';
        }
    });
</script>
