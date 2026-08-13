@extends('layouts.app')

@section('title', 'Inbox')

@section('content')
    <div class="inbox-shell">
        <div class="inbox-toolbar">
            <form method="GET" action="{{ route('inbox.index') }}" class="inbox-search">
                <svg class="ic-sm"><use href="#i-search"/></svg>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Search all mail&hellip;">
                @if ($selectedAccountId)<input type="hidden" name="account" value="{{ $selectedAccountId }}">@endif
                @if ($showArchived)<input type="hidden" name="archived" value="1">@endif
            </form>
            {{-- Only offered once there is a query to save, since the scope on
                 its own is already a folder in the rail (ZERO-120). --}}
            @if (request()->filled('q'))
                <form method="POST" action="{{ route('savedSearches.store') }}" class="save-search"
                      x-data="{ naming: false }" x-on:submit="if (! naming) { naming = true; $nextTick(() => $refs.name.focus()); return false }">
                    @csrf
                    <input type="hidden" name="query" value="{{ request('q') }}">
                    @if ($selectedAccountId)<input type="hidden" name="account" value="{{ $selectedAccountId }}">@endif
                    @if (! $showArchived && ! $showStarred)<input type="hidden" name="folder" value="{{ $folder }}">@endif
                    @if ($showArchived)<input type="hidden" name="archived" value="1">@endif
                    @if ($showStarred)<input type="hidden" name="starred" value="1">@endif
                    <input type="text" name="name" x-ref="name" x-show="naming" x-cloak maxlength="60"
                           placeholder="Name this search&hellip;" aria-label="Name for this saved search"
                           value="{{ Str::limit(request('q'), 55, '') }}">
                    <button class="btn sm ghost" title="Save this search">
                        <svg class="ic-sm"><use href="#i-star"/></svg><span x-text="naming ? 'Save' : 'Save search'"></span>
                    </button>
                </form>
            @endif
            <form method="GET" action="{{ route('inbox.index') }}">
                @if ($showArchived)<input type="hidden" name="archived" value="1">@endif
                <select name="account" onchange="this.form.submit()" class="tb-account" title="Filter by account">
                    <option value="">All accounts</option>
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}" @selected($selectedAccountId == $acc->id)>{{ $acc->email_address }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <div class="inbox-grid {{ $openThread ? 'has-open-thread' : '' }}">
            <div class="pane rail-pane">
                @include('components.folder-nav')
            </div>

            <div class="pane list-pane">
                @php $selectedAccount = $selectedAccountId ? $accounts->firstWhere('id', $selectedAccountId) : null; @endphp
                <div class="pane-head">
                    <div>
                        <h2>{{ $showArchived ? 'Archived' : \App\Models\MailFolder::displayName($folder) }}</h2>
                        <div class="sub">
                            {{ $emails->total() }} {{ Str::plural('conversation', $emails->total()) }}
                            @if ($selectedAccount)
                                · <span style="color:{{ $selectedAccount->color }}">●</span> {{ $selectedAccount->email_address }}
                            @else
                                · all accounts
                            @endif
                        </div>
                    </div>
                    <div class="sort">Newest <svg class="ic-sm"><use href="#i-chev"/></svg></div>
                </div>

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

                <div id="email-list" class="thread-list" data-newest-id="{{ $emails->first()?->id ?? 0 }}">
                    @forelse ($emails as $email)
                        @include('inbox._email_row', ['email' => $email, 'threadCounts' => $threadCounts, 'openEmailId' => $openThread['email']->id ?? null])
                    @empty
                        <p class="empty-hint" id="empty-state">No emails yet. Connect an account and click "Sync now".</p>
                    @endforelse
                </div>

                <div style="padding:12px 16px; border-top:1px solid var(--border-soft);">{{ $emails->links() }}</div>
            </div>

            <div class="pane reading-pane" id="reading-pane">
                @if ($openThread)
                    @include('inbox._reading_pane', $openThread)
                @else
                    <p class="empty-hint">Select a conversation to read it here.</p>
                @endif
            </div>
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
                        // On mobile the panes stack; this reveals the reading card.
                        pane.closest('.inbox-grid')?.classList.add('has-open-thread');
                        if (pushUrl) {
                            history.pushState({ emailId }, '', href);
                        }
                    })
                    .catch(() => {
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
