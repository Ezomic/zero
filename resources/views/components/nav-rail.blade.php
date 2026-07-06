@php
    $navAccounts = auth()->user()->mailAccounts()->get();
    $inboxUnread = $navAccounts->sum(fn ($a) => $a->unreadCount());
    $draftsCount = auth()->user()->drafts()->count();

    $isInbox = request()->routeIs('inbox.index', 'inbox.show') && ! request()->boolean('archived') && request()->get('folder', 'INBOX') === 'INBOX';
    $isSent = request()->routeIs('inbox.index') && request()->get('folder') === 'SENT';
    $isTrash = request()->routeIs('inbox.index') && request()->get('folder') === 'TRASH';
    $isArchived = request()->routeIs('inbox.index') && request()->boolean('archived');
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
    <div class="nav-label">Accounts</div>
    <div class="nav-section" style="margin-bottom:0; flex:1; overflow-y:auto;">
        @foreach ($navAccounts as $navAccount)
            @php $navAccountUnread = $navAccount->unreadCount(); @endphp
            <a href="{{ route('inbox.index', ['account' => $navAccount->id]) }}" class="acct-row">
                <span class="acct-dot" style="background:{{ $navAccount->color }}"></span>
                <span class="name">{{ $navAccount->display_name ?: $navAccount->email_address }}</span>
                @if ($navAccountUnread > 0)
                    <span class="n">{{ $navAccountUnread }}</span>
                @endif
            </a>
        @endforeach
    </div>
@endif

<div class="navrail-foot">
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
