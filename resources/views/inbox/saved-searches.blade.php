@extends('layouts.app')

@section('title', 'Saved searches')

@section('content')
<div style="max-width:820px; margin:0 auto; padding:22px; width:100%;">
    <a href="{{ route('inbox.index') }}" class="rp-back" style="margin-bottom:14px;">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>

    <h2 style="margin:0 0 4px; font-size:17px;">Saved searches</h2>
    <p style="margin:0 0 16px; color:var(--text-dim); font-size:13px;">
        Run a search in the inbox and save it to pin it here and in the rail.
    </p>

    @if ($searches->isEmpty())
        <p class="empty-hint">Nothing saved yet.</p>
    @else
        <div class="saved-manage">
            @foreach ($searches as $saved)
                @php
                    $scope = \App\Support\MailScope::fromSavedSearch($saved);
                    $account = $saved->account($user);
                @endphp
                <div class="card saved-row">
                    <div class="saved-row-main">
                        <form method="POST" action="{{ route('savedSearches.update', $saved) }}" class="saved-rename">
                            @csrf @method('PATCH')
                            <input type="text" name="name" value="{{ $saved->name }}" maxlength="60" required
                                   aria-label="Name for this saved search">
                            <button class="btn sm ghost">Rename</button>
                        </form>

                        <div class="saved-meta">
                            <a href="{{ route('inbox.index', $scope->toQueryParams()) }}">"{{ $saved->query }}"</a>
                            <span>
                                @if ($saved->accountIsMissing($user))
                                    <span style="color:var(--danger);">account removed</span>
                                @elseif ($account)
                                    {{ $account->email_address }}
                                @else
                                    all accounts
                                @endif
                                &middot;
                                @if ($saved->starred) starred
                                @elseif ($saved->archived) archived
                                @else {{ \App\Models\MailFolder::displayName($saved->folder ?: 'INBOX') }}
                                @endif
                                &middot; {{ $counts[$saved->id] ?? 0 }} unread
                            </span>
                        </div>
                    </div>

                    <div class="saved-row-actions">
                        <form method="POST" action="{{ route('savedSearches.move', $saved) }}">
                            @csrf
                            <input type="hidden" name="direction" value="up">
                            <button class="icon-btn" title="Move up" @disabled($loop->first)>&uarr;</button>
                        </form>
                        <form method="POST" action="{{ route('savedSearches.move', $saved) }}">
                            @csrf
                            <input type="hidden" name="direction" value="down">
                            <button class="icon-btn" title="Move down" @disabled($loop->last)>&darr;</button>
                        </form>
                        <form method="POST" action="{{ route('savedSearches.destroy', $saved) }}"
                              onsubmit="return confirm('Delete &quot;{{ $saved->name }}&quot;?')">
                            @csrf @method('DELETE')
                            <button class="icon-btn" title="Delete" style="color:var(--danger);">
                                <svg class="ic-sm"><use href="#i-trash"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
