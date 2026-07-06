@extends('layouts.app')

@section('title', 'Inbox')

@section('content')
    <div class="list-header">
        <div class="list-header-top">
            <h1>{{ $showArchived ? 'Archived' : \App\Models\MailFolder::displayName($folder) }}</h1>

            <form method="GET" style="display:flex; gap:8px;">
                <select name="account" onchange="this.form.submit()" style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:12.5px; font-weight:600;">
                    <option value="">All accounts</option>
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}" @selected(request('account') == $acc->id)>{{ $acc->email_address }}</option>
                    @endforeach
                </select>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search&hellip;" style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:12.5px;">
                <button class="btn sm ghost">Search</button>
            </form>
        </div>

        @unless ($showArchived)
            <div class="folder-tabs">
                @foreach ($folders as $f)
                    <a href="{{ route('inbox.index', array_filter(['folder' => $f, 'account' => $selectedAccountId])) }}"
                       class="folder-tab {{ $folder === $f ? 'active' : '' }}">
                        {{ \App\Models\MailFolder::displayName($f) }}
                    </a>
                @endforeach
            </div>
            @unless ($selectedAccountId)
                <p style="font-size:11.5px; color:var(--text-faint); margin:8px 0 0;">Select an account above to see its custom folders too.</p>
            @endunless
        @endunless
    </div>

    <form method="POST" action="{{ route('inbox.bulk') }}" id="bulk-form">
        @csrf
        <div class="toolbar">
            <input type="checkbox" id="select-all">
            <span style="font-size:12.5px; color:var(--text-faint);">Select all</span>
            <div class="sep"></div>

            @unless ($showArchived)
                <button type="submit" name="action" value="archive" class="btn sm ghost"><svg class="ic-sm"><use href="#i-archive"/></svg>Archive</button>
            @else
                <button type="submit" name="action" value="unarchive" class="btn sm ghost"><svg class="ic-sm"><use href="#i-archive"/></svg>Move to inbox</button>
            @endunless
            <button type="submit" name="action" value="read" class="btn sm ghost"><svg class="ic-sm"><use href="#i-check"/></svg>Mark read</button>
            <button type="submit" name="action" value="unread" class="btn sm ghost">Mark unread</button>
            <button type="submit" name="action" value="delete" class="btn sm ghost danger" onclick="return confirm('Delete selected conversations?')"><svg class="ic-sm"><use href="#i-trash"/></svg>Delete</button>
        </div>
    </form>

    {{--
        The row checkboxes below submit via the form="bulk-form" attribute rather
        than being nested inside <form id="bulk-form"> above — each row also carries
        its own hidden per-row forms (archive/unread/delete) for keyboard shortcuts,
        and nesting a <form> inside another <form> is invalid HTML that browsers
        silently mangle (the outer form closes early at the first nested one),
        which would otherwise drop every row checkbox out of the bulk-select form.
    --}}
    <div class="thread-list" id="email-list" data-newest-id="{{ $emails->first()?->id ?? 0 }}">
        @forelse ($emails as $email)
            @include('inbox._email_row', ['email' => $email, 'threadCounts' => $threadCounts])
        @empty
            <p class="empty-hint" id="empty-state">No emails yet. Connect an account and click "Sync now".</p>
        @endforelse
    </div>

    <div style="padding:14px 18px;">{{ $emails->links() }}</div>
@endsection

@section('scripts')
    <script>
        document.getElementById('select-all')?.addEventListener('change', function () {
            document.querySelectorAll('.row-checkbox').forEach((cb) => { cb.checked = this.checked; });
        });
    </script>
@endsection
