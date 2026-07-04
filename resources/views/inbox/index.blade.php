@extends('layouts.app')

@section('title', 'Inbox')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">{{ $showArchived ? 'Archived' : \App\Models\MailFolder::displayName($folder) }}</h1>

        <form method="GET" class="flex gap-2">
            <select name="account" class="border rounded px-3 py-2 text-sm" onchange="this.form.submit()">
                <option value="">All accounts</option>
                @foreach ($accounts as $acc)
                    <option value="{{ $acc->id }}" @selected(request('account') == $acc->id)>{{ $acc->email_address }}</option>
                @endforeach
            </select>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Search…" class="border rounded px-3 py-2 text-sm">
            <button class="px-3 py-2 border rounded text-sm">Search</button>
        </form>
    </div>

    @unless ($showArchived)
        <div class="flex gap-1 mb-4 text-sm flex-wrap">
            @foreach ($folders as $f)
                <a href="{{ route('inbox.index', array_filter(['folder' => $f, 'account' => $selectedAccountId])) }}"
                   class="px-3 py-1.5 rounded {{ $folder === $f ? 'bg-blue-600 text-white' : 'bg-white border' }}">
                    {{ \App\Models\MailFolder::displayName($f) }}
                </a>
            @endforeach
        </div>
        @unless ($selectedAccountId)
            <p class="text-xs text-gray-400 -mt-3 mb-4">Select an account above to see its custom folders too.</p>
        @endunless
    @endunless

    <form method="POST" action="{{ route('inbox.bulk') }}" id="bulk-form">
        @csrf
        <div class="flex items-center gap-2 mb-2">
            <label class="text-sm text-gray-500 flex items-center gap-2">
                <input type="checkbox" id="select-all">
                Select all
            </label>

            @unless ($showArchived)
                <button type="submit" name="action" value="archive" class="text-sm px-3 py-1 rounded border">Archive</button>
            @else
                <button type="submit" name="action" value="unarchive" class="text-sm px-3 py-1 rounded border">Move to inbox</button>
            @endunless
            <button type="submit" name="action" value="read" class="text-sm px-3 py-1 rounded border">Mark read</button>
            <button type="submit" name="action" value="unread" class="text-sm px-3 py-1 rounded border">Mark unread</button>
            <button type="submit" name="action" value="delete" class="text-sm px-3 py-1 rounded border text-red-600" onclick="return confirm('Delete selected conversations?')">Delete</button>
        </div>

        <div class="bg-white rounded border divide-y">
            @forelse ($emails as $email)
                <div class="email-row flex items-center gap-3 p-4 hover:bg-gray-50 focus-within:ring-1 focus-within:ring-blue-300 {{ $email->is_read ? '' : 'bg-blue-50' }}">
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
            @empty
                <p class="p-4 text-gray-500">No emails yet. Connect an account and click "Sync now".</p>
            @endforelse
        </div>
    </form>

    <div class="mt-4">{{ $emails->links() }}</div>
@endsection

@section('scripts')
    <script>
        document.getElementById('select-all')?.addEventListener('change', function () {
            document.querySelectorAll('.row-checkbox').forEach((cb) => { cb.checked = this.checked; });
        });
    </script>
@endsection
