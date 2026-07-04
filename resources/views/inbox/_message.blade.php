<div class="bg-white rounded border p-6">
    <div class="flex items-center justify-between mb-1">
        <div>
            <span class="font-semibold">{{ $message->from_name ?: $message->from_address }}</span>
            <span class="text-sm text-gray-500">&lt;{{ $message->from_address }}&gt;</span>
        </div>
        <div class="text-xs text-gray-400">{{ $message->sent_at?->format('M j, Y g:i A') }}</div>
    </div>
    <div class="text-xs text-gray-400 mb-4 flex items-center gap-1">
        <span class="w-2 h-2 rounded-full inline-block" style="background-color: {{ $message->mailAccount->color }}"></span>
        via {{ $message->mailAccount->email_address }} &middot; {{ \App\Models\MailFolder::displayName($message->folder) }}
    </div>

    @if ($message->body_html)
        <iframe
            srcdoc="{{ $message->body_html }}"
            sandbox="allow-same-origin"
            referrerpolicy="no-referrer"
            class="w-full border-0 min-h-[120px]"
            onload="this.style.height = (this.contentDocument.documentElement.scrollHeight + 16) + 'px'"
        ></iframe>
    @elseif ($message->body_text)
        <div class="prose max-w-none whitespace-pre-wrap font-sans text-sm">{{ $message->body_text }}</div>
    @endif

    @if ($message->attachments->isNotEmpty())
        <div class="mt-6 border-t pt-4">
            <h2 class="text-sm font-semibold mb-2">Attachments</h2>
            <ul class="text-sm space-y-1">
                @foreach ($message->attachments as $attachment)
                    <li>{{ $attachment->filename }} ({{ number_format(($attachment->size_bytes ?? 0) / 1024, 1) }} KB)</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-4 pt-4 border-t flex gap-2 text-sm">
        <a href="{{ route('compose.reply', $message) }}" class="px-3 py-1 rounded border">Reply</a>
        <a href="{{ route('compose.replyAll', $message) }}" class="px-3 py-1 rounded border">Reply All</a>
        <a href="{{ route('compose.forward', $message) }}" class="px-3 py-1 rounded border">Forward</a>
    </div>
</div>
