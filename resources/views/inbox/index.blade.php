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

        <div class="bg-white rounded border divide-y" id="email-list" data-newest-id="{{ $emails->first()?->id ?? 0 }}">
            @forelse ($emails as $email)
                @include('inbox._email_row', ['email' => $email, 'threadCounts' => $threadCounts])
            @empty
                <p class="p-4 text-gray-500" id="empty-state">No emails yet. Connect an account and click "Sync now".</p>
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
