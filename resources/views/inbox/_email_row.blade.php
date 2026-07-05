<div class="email-row flex items-center gap-3 p-4 hover:bg-gray-50 focus-within:ring-1 focus-within:ring-blue-300 {{ $email->is_read ? '' : 'bg-blue-50' }}" data-email-id="{{ $email->id }}">
    <input type="checkbox" name="ids[]" value="{{ $email->id }}" class="row-checkbox">
    <a href="{{ route('inbox.show', $email) }}" class="flex-1 min-w-0 block">
        <div class="flex justify-between text-sm">
            <span class="font-medium {{ $email->is_read ? '' : 'font-bold' }}">
                {{ $email->from_name ?: $email->from_address }}
            </span>
            <span class="text-gray-400">{{ $email->sent_at?->format('M j, g:i A') }}</span>
        </div>
        <div class="{{ $email->is_read ? '' : 'font-semibold' }} truncate">
            {{ $email->subject }}
            @if (($threadCounts[$email->thread_id] ?? 1) > 1)
                <span class="text-xs text-gray-400 font-normal">({{ $threadCounts[$email->thread_id] }})</span>
            @endif
        </div>
        <div class="text-sm text-gray-500 truncate">{{ Str::limit(strip_tags($email->body_text ?: $email->body_html), 120) }}</div>
        <div class="text-xs text-gray-400 mt-1 flex items-center gap-1">
            <span class="w-2 h-2 rounded-full inline-block" style="background-color: {{ $email->mailAccount->color }}"></span>
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
