@extends('layouts.app')

@section('title', 'Accounts')

@section('content')
    <h1 class="text-xl font-semibold mb-4">Connected accounts</h1>

    @if (session('error'))
        <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-700 text-sm">{{ session('error') }}</div>
    @endif
    @if (session('status'))
        <div class="mb-4 p-3 rounded bg-green-50 border border-green-200 text-green-700 text-sm">{{ session('status') }}</div>
    @endif

    <div class="flex gap-2 mb-6">
        <a href="{{ route('auth.google.redirect') }}" class="px-4 py-2 rounded bg-white border shadow-sm">Connect Gmail</a>
        <a href="{{ route('auth.microsoft.redirect') }}" class="px-4 py-2 rounded bg-white border shadow-sm">Connect Outlook / Hotmail</a>
        <a href="{{ route('accounts.create') }}" class="px-4 py-2 rounded bg-white border shadow-sm">Add custom IMAP/SMTP</a>
    </div>

    <div class="bg-white rounded border divide-y">
        @forelse ($accounts as $account)
            <div class="p-4 flex items-center justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">
                        <span class="inline-block w-2 h-2 rounded-full mr-1" style="background:{{ $account->color }}"></span>
                        {{ $account->email_address }}
                        <span class="text-xs uppercase text-gray-400 ml-2">{{ $account->provider }}</span>
                        @unless ($account->is_active)
                            <span class="text-xs uppercase text-gray-400 ml-2">(inactive)</span>
                        @endunless
                    </div>
                    <div class="text-sm mt-0.5">
                        @if ($account->sync_status === 'error')
                            <span class="inline-flex items-center gap-1 text-red-600">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                {{ $account->sync_error ?? 'Sync failed' }}
                            </span>
                            @if ($account->sync_status_since)
                                <span class="text-gray-400 ml-1">· {{ $account->sync_status_since->diffForHumans() }}</span>
                            @endif
                        @elseif ($account->sync_status === 'syncing')
                            <span class="text-blue-600">Syncing…</span>
                            @if ($account->sync_status_since)
                                <span class="text-gray-400 ml-1">· since {{ $account->sync_status_since->diffForHumans() }}</span>
                            @endif
                        @else
                            <span class="text-gray-400">
                                @if ($account->last_synced_at)
                                    Synced {{ $account->last_synced_at->diffForHumans() }}
                                @else
                                    Never synced
                                @endif
                            </span>
                        @endif
                    </div>
                </div>
                <div class="flex gap-2 flex-shrink-0">
                    @if (! $account->is_active)
                        <form method="POST" action="{{ route('accounts.reenable', $account) }}">
                            @csrf
                            <button class="text-sm px-3 py-1 rounded border border-orange-300 text-orange-600 font-medium">Re-enable</button>
                        </form>
                    @elseif ($account->sync_status === 'error')
                        @if ($account->provider === 'gmail')
                            <a href="{{ route('auth.google.redirect') }}" class="text-sm px-3 py-1 rounded border border-red-300 text-red-600 font-medium">Reconnect</a>
                        @elseif ($account->provider === 'outlook')
                            <a href="{{ route('auth.microsoft.redirect') }}" class="text-sm px-3 py-1 rounded border border-red-300 text-red-600 font-medium">Reconnect</a>
                        @else
                            <a href="{{ route('accounts.edit', $account) }}" class="text-sm px-3 py-1 rounded border border-red-300 text-red-600 font-medium">Update credentials</a>
                        @endif
                    @endif
                    <a href="{{ route('accounts.edit', $account) }}" class="text-sm px-3 py-1 rounded border">Edit</a>
                    <form method="POST" action="{{ route('accounts.sync', $account) }}">
                        @csrf
                        <button class="text-sm px-3 py-1 rounded border">Sync now</button>
                    </form>
                    <form method="POST" action="{{ route('accounts.destroy', $account) }}" onsubmit="return confirm('Remove this account?')">
                        @csrf @method('DELETE')
                        <button class="text-sm px-3 py-1 rounded border text-red-600">Remove</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="p-4 text-gray-500">No accounts connected yet.</p>
        @endforelse
    </div>
@endsection
