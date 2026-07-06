@php
    $rpInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $email->from_name ?: $email->from_address), 0, 2)) ?: '??';
@endphp
<div class="rp-header">
    <div>
        <h2>{{ $email->subject }}</h2>
        <div class="rp-chips">
            <div class="chip">
                <span class="dot" style="background:{{ $email->mailAccount->color }}">{{ $rpInitials }}</span>
                {{ $email->from_name ?: $email->from_address }}
            </div>
            <div class="chip" style="color:var(--text-faint);">via {{ $email->mailAccount->email_address }}</div>
        </div>
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
    @foreach ($messages as $message)
        @include('inbox._message', ['message' => $message])
    @endforeach
</div>
