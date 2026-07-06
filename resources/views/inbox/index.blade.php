@extends('layouts.app')

@section('title', 'Inbox')

@section('content')
    <div class="list-header">
        <div class="list-header-top">
            <h1>{{ $showArchived ? 'Archived' : \App\Models\MailFolder::displayName($folder) }}</h1>

            <form method="GET" action="{{ route('inbox.index') }}" style="display:flex; gap:8px;">
                <select name="account" onchange="this.form.submit()" style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:12.5px; font-weight:600;">
                    <option value="">All accounts</option>
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}" @selected($selectedAccountId == $acc->id)>{{ $acc->email_address }}</option>
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

    <div class="inbox-body">
        <div class="thread-list">
            {{--
                The row checkboxes below submit via the form="bulk-form" attribute rather
                than being nested inside <form id="bulk-form"> here — each row also carries
                its own hidden per-row forms (archive/unread/delete) for keyboard shortcuts,
                and nesting a <form> inside another <form> is invalid HTML that browsers
                silently mangle (the outer form closes early at the first nested one),
                which would otherwise drop every row checkbox out of the bulk-select form.
            --}}
            <form method="POST" action="{{ route('inbox.bulk') }}" id="bulk-form">
                @csrf
                <div class="toolbar">
                    <input type="checkbox" id="select-all" title="Select all">
                    <div class="sep"></div>

                    @unless ($showArchived)
                        <button type="submit" name="action" value="archive" class="icon-btn" title="Archive"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
                    @else
                        <button type="submit" name="action" value="unarchive" class="icon-btn" title="Move to inbox"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
                    @endunless
                    <button type="submit" name="action" value="read" class="icon-btn" title="Mark read"><svg class="ic-sm"><use href="#i-check"/></svg></button>
                    <button type="submit" name="action" value="unread" class="icon-btn" title="Mark unread"><svg class="ic-sm"><use href="#i-unread"/></svg></button>
                    <button type="submit" name="action" value="delete" class="icon-btn" title="Delete" style="color:var(--danger);" onclick="return confirm('Delete selected conversations?')"><svg class="ic-sm"><use href="#i-trash"/></svg></button>
                </div>
            </form>

            <div id="email-list" data-newest-id="{{ $emails->first()?->id ?? 0 }}">
                @forelse ($emails as $email)
                    @include('inbox._email_row', ['email' => $email, 'threadCounts' => $threadCounts, 'openEmailId' => $openThread['email']->id ?? null])
                @empty
                    <p class="empty-hint" id="empty-state">No emails yet. Connect an account and click "Sync now".</p>
                @endforelse
            </div>

            <div style="padding:14px 18px;">{{ $emails->links() }}</div>
        </div>

        <div class="reading-pane" id="reading-pane">
            @if ($openThread)
                @include('inbox._reading_pane', $openThread)
            @else
                <p class="empty-hint">Select a conversation to read it here.</p>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.getElementById('select-all')?.addEventListener('change', function () {
            document.querySelectorAll('.row-checkbox').forEach((cb) => { cb.checked = this.checked; });
        });

        (function () {
            const pane = document.getElementById('reading-pane');
            const list = document.getElementById('email-list');
            if (!pane || !list) return;

            function markSelected(emailId) {
                list.querySelectorAll('.trow.selected').forEach((row) => row.classList.remove('selected'));
                const row = list.querySelector(`[data-email-id="${emailId}"]`);
                row?.classList.add('selected');
            }

            function openThread(emailId, href, pushUrl) {
                fetch(`/emails/${emailId}/panel`, { headers: { 'Accept': 'text/html' } })
                    .then((r) => {
                        if (!r.ok) throw new Error('panel fetch failed');
                        return r.text();
                    })
                    .then((html) => {
                        pane.innerHTML = html;
                        markSelected(emailId);
                        if (pushUrl) {
                            history.pushState({ emailId }, '', href);
                        }
                    })
                    .catch(() => {
                        // Fall back to a real navigation if the panel fetch fails.
                        location.href = href;
                    });
            }

            list.querySelectorAll('.trow-main').forEach((link) => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const row = this.closest('[data-email-id]');
                    openThread(row.dataset.emailId, this.href, true);
                });
            });

            window.__openInboxThread = function (row) {
                const link = row?.querySelector('.trow-main');
                if (link) openThread(row.dataset.emailId, link.href, true);
            };

            window.addEventListener('popstate', () => location.reload());
        })();
    </script>
@endsection
