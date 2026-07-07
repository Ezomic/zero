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
                        <div x-data="{ open: false, confirmText: '' }" style="margin-left:auto;">
                            <button type="button" class="btn sm ghost danger" @click="open = true">Remove</button>

                            <div x-show="open" class="backdrop" @click="open = false" style="z-index:60;"></div>
                            <div
                                x-show="open"
                                style="position:fixed; inset:0; z-index:61; display:flex; align-items:center; justify-content:center; padding:16px;"
                            >
                                <div style="background:var(--bg-1); border:1px solid var(--border); border-radius:12px; padding:20px; max-width:380px; width:100%;">
                                    <h2 style="font-size:15px; font-weight:700; margin:0 0 6px;">Remove {{ $account->email_address }}?</h2>
                                    <p style="font-size:13px; color:var(--text-dim); margin:0 0 12px;">
                                        This permanently deletes all synced emails and folders for this account. There's no undo. Type the email address to confirm.
                                    </p>

                                    <form method="POST" action="{{ route('accounts.destroy', $account) }}">
                                        @csrf @method('DELETE')
                                        <input
                                            type="text"
                                            x-model="confirmText"
                                            placeholder="{{ $account->email_address }}"
                                            autocomplete="off"
                                            class="btn"
                                            style="width:100%; text-align:left; cursor:text; margin-bottom:14px;"
                                        >
                                        <div style="display:flex; justify-content:flex-end; gap:8px;">
                                            <button type="button" class="btn sm ghost" @click="open = false; confirmText = ''">Cancel</button>
                                            <button
                                                type="submit"
                                                class="btn sm danger"
                                                :disabled="confirmText !== '{{ $account->email_address }}'"
                                                :style="confirmText !== '{{ $account->email_address }}' ? 'opacity:.4; cursor:not-allowed;' : ''"
                                            >Remove account</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <p style="color:var(--text-faint);">No accounts connected yet.</p>
            @endforelse
        </div>
    </div>
@endsection
