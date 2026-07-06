@php
    $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $message->from_name ?: $message->from_address), 0, 2)) ?: '??';
@endphp
<div class="msg">
    <div class="msg-head">
        <div class="avatar" style="background:{{ $message->mailAccount->color }}; width:30px; height:30px; font-size:11px;">{{ $initials }}</div>
        <div>
            <span class="who">{{ $message->from_name ?: $message->from_address }}</span>
            <span class="addr">&lt;{{ $message->from_address }}&gt;</span>
        </div>
        <div class="when">{{ $message->sent_at?->format('M j, Y g:i A') }}</div>
    </div>
    <div style="padding:0 16px 10px; font-size:11.5px; color:var(--text-faint); display:flex; align-items:center; gap:6px; margin-top:-8px;">
        <span class="acct-dot" style="background:{{ $message->mailAccount->color }}"></span>
        via {{ $message->mailAccount->email_address }} &middot; {{ \App\Models\MailFolder::displayName($message->folder) }}
    </div>

    @if ($message->body_html)
        <div class="msg-body" style="padding-top:0; border-top:none;">
            <iframe
                srcdoc="{{ $message->body_html }}"
                sandbox="allow-same-origin"
                referrerpolicy="no-referrer"
                style="min-height:120px;"
                onload="this.style.height = (this.contentDocument.documentElement.scrollHeight + 16) + 'px'"
            ></iframe>
        </div>
    @elseif ($message->body_text)
        <div class="msg-body" style="padding-top:0; border-top:none; white-space:pre-wrap;">{{ $message->body_text }}</div>
    @endif

    @if ($message->attachments->isNotEmpty())
        <div class="attach-row">
            @foreach ($message->attachments as $attachment)
                <div class="attach-chip"><svg class="ic-sm"><use href="#i-clip"/></svg>{{ $attachment->filename }} &middot; {{ number_format(($attachment->size_bytes ?? 0) / 1024, 1) }} KB</div>
            @endforeach
        </div>
    @endif

    <div style="display:flex; gap:6px; padding:12px 16px; border-top:1px solid var(--border-soft);">
        <a href="{{ route('compose.reply', $message) }}" class="btn sm ghost"><svg class="ic-sm"><use href="#i-reply"/></svg>Reply</a>
        <a href="{{ route('compose.replyAll', $message) }}" class="btn sm ghost">Reply All</a>
        <a href="{{ route('compose.forward', $message) }}" class="btn sm ghost">Forward</a>
    </div>
</div>
