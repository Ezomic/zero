@extends('layouts.app')

@section('title', 'Accounts')

@section('content')
    <div class="page-pad" style="width:100%;">
        <div class="page-head"><h1>Connected accounts</h1></div>

        @if (session('error'))
            <div class="flash error"><svg class="ic-sm"><use href="#i-alert"/></svg>{{ session('error') }}</div>
        @endif
        @if (session('status'))
            <div class="flash success"><svg class="ic-sm"><use href="#i-check"/></svg>{{ session('status') }}</div>
        @endif

        <div class="add-tiles">
            <a href="{{ route('auth.google.redirect') }}" class="add-tile">
                <div class="glyph" style="background:#EA4335">G</div>
                <div><div class="t1">Connect Gmail</div><div class="t2">OAuth &middot; read + send</div></div>
            </a>
            <a href="{{ route('auth.microsoft.redirect') }}" class="add-tile">
                <div class="glyph" style="background:#0364B8">O</div>
                <div><div class="t1">Connect Outlook / Hotmail</div><div class="t2">OAuth &middot; read + send</div></div>
            </a>
            <a href="{{ route('accounts.create') }}" class="add-tile">
                <div class="glyph" style="background:var(--accent)"><svg class="ic-sm" style="color:#fff"><use href="#i-plus"/></svg></div>
                <div><div class="t1">Custom IMAP/SMTP</div><div class="t2">Any mailbox</div></div>
            </a>
        </div>

        <div class="acct-grid">
            @forelse ($accounts as $account)
                @php
                    $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $account->display_name ?: $account->email_address), 0, 2)) ?: '??';
                @endphp
                <div class="acct-card">
                    <div class="acct-card-top">
                        <div class="acct-ring" style="background:{{ $account->color }}">{{ $initials }}</div>
                        <div style="min-width:0;">
                            <div class="addr">{{ $account->email_address }}</div>
                            <div class="provider">{{ $account->provider }}{{ $account->is_active ? '' : ' &middot; inactive' }}</div>
                        </div>
                    </div>

                    <div class="acct-status">
                        @if ($account->sync_status === 'error')
                            <span class="pill danger"><svg class="ic-sm"><use href="#i-alert"/></svg>{{ $account->sync_error ?? 'Sync failed' }}</span>
                            @if ($account->sync_status_since)
                                <span style="color:var(--text-faint);">&middot; {{ $account->sync_status_since->diffForHumans() }}</span>
                            @endif
                        @elseif ($account->sync_status === 'syncing')
                            <span class="pill warn"><svg class="ic-sm"><use href="#i-refresh"/></svg>Syncing&hellip;</span>
                            @if ($account->sync_status_since)
                                <span style="color:var(--text-faint);">&middot; since {{ $account->sync_status_since->diffForHumans() }}</span>
                            @endif
                        @else
                            <span class="pill success">
                                <svg class="ic-sm"><use href="#i-check"/></svg>
                                @if ($account->last_synced_at)
                                    Synced {{ $account->last_synced_at->diffForHumans() }}
                                @else
                                    Never synced
                                @endif
                            </span>
                        @endif
                    </div>

                    <div class="acct-card-foot">
                        @if (! $account->is_active)
                            <form method="POST" action="{{ route('accounts.reenable', $account) }}">
                                @csrf
                                <button class="btn sm" style="color:var(--warning); border-color:var(--warning);">Re-enable</button>
                            </form>
                        @elseif ($account->sync_status === 'error')
                            @if ($account->provider === 'gmail')
                                <a href="{{ route('auth.google.redirect') }}" class="btn sm" style="color:var(--danger); border-color:var(--danger);">Reconnect</a>
                            @elseif ($account->provider === 'outlook')
                                <a href="{{ route('auth.microsoft.redirect') }}" class="btn sm" style="color:var(--danger); border-color:var(--danger);">Reconnect</a>
                            @else
                                <a href="{{ route('accounts.edit', $account) }}" class="btn sm" style="color:var(--danger); border-color:var(--danger);">Update credentials</a>
                            @endif
                        @endif
                        <a href="{{ route('accounts.edit', $account) }}" class="btn sm ghost"><svg class="ic-sm"><use href="#i-pencil"/></svg>Edit</a>
                        <form method="POST" action="{{ route('accounts.sync', $account) }}">
                            @csrf
                            <button class="btn sm ghost"><svg class="ic-sm"><use href="#i-refresh"/></svg>Sync</button>
                        </form>
                        <form method="POST" action="{{ route('accounts.destroy', $account) }}" onsubmit="return confirm('Remove this account?')" style="margin-left:auto;">
                            @csrf @method('DELETE')
                            <button class="btn sm ghost danger">Remove</button>
                        </form>
                    </div>
                </div>
            @empty
                <p style="color:var(--text-faint);">No accounts connected yet.</p>
            @endforelse
        </div>
    </div>
@endsection
