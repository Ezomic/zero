@php
    $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $email->from_name ?: $email->from_address), 0, 2)) ?: '??';
@endphp
<div class="email-row trow {{ $email->is_read ? '' : 'unread' }} {{ $email->id === ($openEmailId ?? null) ? 'selected' : '' }}" data-email-id="{{ $email->id }}">
    <input type="checkbox" name="ids[]" value="{{ $email->id }}" class="row-checkbox" form="bulk-form">
    <div class="avatar" style="background:{{ $email->mailAccount->color }}">{{ $initials }}</div>
    <a href="{{ route('inbox.show', $email) }}" class="trow-main">
        <div class="trow-top">
            <span class="trow-from">{{ $email->from_name ?: $email->from_address }}</span>
            <span class="trow-time">{{ $email->sent_at?->format('M j, g:i A') }}</span>
        </div>
        <div class="trow-subject">
            {{ $email->subject }}
            @if (($threadCounts[$email->thread_id] ?? 1) > 1)
                <span class="trow-badge">{{ $threadCounts[$email->thread_id] }}</span>
            @endif
        </div>
        <div class="trow-snippet">{{ Str::limit(strip_tags($email->body_text ?: $email->body_html), 120) }}</div>
        <div class="trow-account">
            <span class="acct-dot" style="background:{{ $email->mailAccount->color }}"></span>
            {{ $email->mailAccount->email_address }}
        </div>
    </a>
    {{-- Hidden per-row action forms for keyboard shortcuts --}}
    <div class="hidden">
        <form method="POST" action="{{ route('inbox.archive', $email) }}" data-action="archive">@csrf</form>
        <form method="POST" action="{{ route('inbox.markUnread', $email) }}" data-action="unread">@csrf</form>
        <form method="POST" action="{{ route('inbox.destroy', $email) }}" data-action="delete">@csrf @method('DELETE')</form>
        <a href="{{ route('compose.reply', $email) }}" data-action="reply"></a>
    </div>
</div>
