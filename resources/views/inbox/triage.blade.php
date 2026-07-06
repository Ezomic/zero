@extends('layouts.app')

@section('title', 'Process Inbox')

@section('content')
    <div class="triage-wrap">
        <div class="triage-progress">
            <span style="font-weight:800; font-size:18px;">Process Inbox</span>

            @if ($accounts->isNotEmpty())
                <form method="GET">
                    <select name="account" onchange="this.form.submit()" style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:12.5px; font-weight:600;">
                        @foreach ($accounts as $acc)
                            <option value="{{ $acc->id }}" @selected($account && $account->id === $acc->id)>{{ $acc->email_address }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>

        @if (! $account)
            <p style="color:var(--text-faint);">Connect an account first.</p>
        @elseif (! $email)
            <div class="triage-empty">
                <p style="font-size:16px; font-weight:700; margin:0 0 4px;">Inbox zero!</p>
                <p style="font-size:13px; color:var(--text-faint); margin:0;">Nothing left to process in {{ $account->email_address }}.</p>

                @if ($skippedCount > 0)
                    <form method="POST" action="{{ route('triage.resetSkipped') }}" style="margin-top:16px;">
                        @csrf
                        <input type="hidden" name="account" value="{{ $account->id }}">
                        <button class="btn sm ghost">Show {{ $skippedCount }} skipped conversation(s) again</button>
                    </form>
                @endif
            </div>
        @else
            @php $dotCount = min($remaining, 12); @endphp
            <div class="dots">
                @for ($i = 0; $i < $dotCount; $i++)
                    <i></i>
                @endfor
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; font-size:13px; color:var(--text-dim); font-weight:700; margin:-8px 0 12px;">
                <span>{{ $remaining }} left &middot; {{ $account->email_address }}</span>
                @if ($skippedCount > 0)
                    <span style="font-weight:600; color:var(--text-faint);">{{ $skippedCount }} skipped this session</span>
                @endif
            </div>

            <div class="triage-card">
                <div class="triage-card-top">
                    <div>
                        <div class="triage-from">{{ $email->from_name ?: $email->from_address }}</div>
                        <div class="triage-addr">{{ $email->from_address }}</div>
                    </div>
                    <div class="pill neutral">{{ $email->sent_at?->format('M j, Y g:i A') }}</div>
                </div>

                <div class="triage-subject">{{ $email->subject }}</div>

                <div class="triage-preview">
                    {!! $email->body_html ?: nl2br(e($email->body_text ?: '(no preview available)')) !!}
                </div>

                <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border-soft);">
                    <a href="{{ route('inbox.show', $email) }}" style="font-size:12.5px; color:var(--text-faint); text-decoration:underline;">View full conversation &amp; reply</a>
                </div>
            </div>

            @if ($suggestedFolder)
                <form method="POST" action="{{ route('triage.move', $email) }}">
                    @csrf
                    <input type="hidden" name="folder" value="{{ $suggestedFolder }}">
                    <button class="triage-suggest" title="Shortcut: Enter">
                        <span>&#10024; Suggested: mark read &amp; move to {{ \App\Models\MailFolder::displayName($suggestedFolder) }}</span>
                        <small>based on mail from this sender</small>
                    </button>
                </form>
            @endif

            <div class="triage-actions">
                <form method="POST" action="{{ route('triage.delete', $email) }}" onsubmit="return confirm('Delete this conversation?')">
                    @csrf
                    <button class="btn danger" title="Shortcut: D">Delete</button>
                </form>

                <form method="POST" action="{{ route('triage.skip', $email) }}">
                    @csrf
                    <button class="btn ghost" title="Shortcut: S">Skip</button>
                </form>

                <form method="POST" action="{{ route('triage.move', $email) }}" style="display:flex; gap:6px; flex:2;">
                    @csrf
                    <select name="folder" required style="flex:1; padding:0 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:13px;">
                        <option value="" disabled {{ $suggestedFolder ? '' : 'selected' }}>Move to&hellip;</option>
                        @foreach ($folders as $f)
                            <option value="{{ $f }}" @selected($f === $suggestedFolder)>{{ \App\Models\MailFolder::displayName($f) }}</option>
                        @endforeach
                    </select>
                    <button class="btn primary">Mark read &amp; move</button>
                </form>
            </div>

            <p class="triage-key">
                <kbd>D</kbd> delete &nbsp; <kbd>S</kbd> skip
                @if ($suggestedFolder)
                    &nbsp; <kbd>Enter</kbd> accept suggestion
                @endif
            </p>
        @endif
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('keydown', function (e) {
            const tag = e.target.tagName;
            if (tag === 'SELECT' || tag === 'INPUT' || tag === 'TEXTAREA') return;

            if (e.key === 'd' || e.key === 'D') {
                document.querySelector('form[action$="/delete"] button')?.click();
            } else if (e.key === 's' || e.key === 'S') {
                document.querySelector('form[action$="/skip"] button')?.click();
            } else if (e.key === 'Enter') {
                document.querySelector('form[action$="/move"] input[name="folder"]')?.closest('form')?.querySelector('button')?.click();
            }
        });
    </script>
@endsection
