@php
    $rpInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $email->from_name ?: $email->from_address), 0, 2)) ?: '??';
@endphp
<div class="rp-header">
    <a href="{{ route('inbox.index') }}" class="rp-back" aria-label="Back to inbox">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>
    <div>
        <h2>
            {{ $email->subject }}
            @if (count($messages) > 1)
                <span class="rp-count" title="{{ count($messages) }} messages in this conversation">{{ count($messages) }}</span>
            @endif
        </h2>
        <div class="rp-chips">
            <div class="chip">
                <span class="dot" style="background:{{ $email->mailAccount->color }}">{{ $rpInitials }}</span>
                {{ $email->from_name ?: $email->from_address }}
            </div>
            <div class="chip" style="color:var(--text-faint);">via {{ $email->mailAccount->email_address }}</div>
        </div>
        @php
            $rpTo = $email->recipientList();
            $rpCc = $email->ccList();
        @endphp
        @if ($rpTo)
            <div class="rp-recipients" style="color:var(--text-faint); font-size:13px; margin-top:4px;">
                <span style="font-weight:600;">To:</span> {{ implode(', ', $rpTo) }}
            </div>
        @endif
        @if ($rpCc)
            <div class="rp-recipients" style="color:var(--text-faint); font-size:13px;">
                <span style="font-weight:600;">Cc:</span> {{ implode(', ', $rpCc) }}
            </div>
        @endif
    </div>
    <div class="rp-actions">
        @if ($email->is_archived)
            <form method="POST" action="{{ route('inbox.unarchive', $email) }}">
                @csrf
                <button class="icon-btn" title="Move to inbox"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
            </form>
        @else
            <form method="POST" action="{{ route('inbox.archive', $email) }}">
                @csrf
                <button class="icon-btn" title="Archive"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
            </form>
        @endif
        <form method="POST" action="{{ route('inbox.markUnread', $email) }}">
            @csrf
            <button class="icon-btn" title="Mark unread"><svg class="ic-sm"><use href="#i-check"/></svg></button>
        </form>
        <form method="POST" action="{{ route('inbox.destroy', $email) }}" onsubmit="return confirm('Delete this conversation?')">
            @csrf @method('DELETE')
            <button class="icon-btn" title="Delete" style="color:var(--danger);"><svg class="ic-sm"><use href="#i-trash"/></svg></button>
        </form>
    </div>
</div>

@if (count($availableFolders) > 1)
    <div style="padding:14px 22px 0;">
        <form method="POST" action="{{ route('inbox.move', $email) }}" style="display:flex; gap:8px;">
            @csrf
            <select name="folder" required style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:12.5px;">
                <option value="" disabled {{ $suggestedFolder ? '' : 'selected' }}>Move to&hellip;</option>
                @foreach ($availableFolders as $f)
                    @unless ($f === $email->folder)
                        <option value="{{ $f }}" @selected($f === $suggestedFolder)>
                            {{ \App\Models\MailFolder::displayName($f) }}{{ $f === $suggestedFolder ? ' (suggested)' : '' }}
                        </option>
                    @endunless
                @endforeach
            </select>
            <button class="btn sm ghost">Move</button>
        </form>
    </div>
@endif

<div class="rp-body">
    {{-- Oldest first, so the last one is the newest: that is the one worth
         reading on open, and the rest collapse to a summary line. --}}
    @foreach ($messages as $message)
        @include('inbox._message', ['message' => $message, 'expanded' => $loop->last])
    @endforeach
</div>
