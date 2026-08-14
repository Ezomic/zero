@extends('layouts.app')

@section('title', 'Snoozed')

@section('content')
<div style="max-width:900px; margin:0 auto; padding:22px; width:100%;">
    <a href="{{ route('inbox.index') }}" class="rp-back" style="margin-bottom:14px;">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>

    <h2 style="margin:0 0 4px; font-size:17px;">Snoozed</h2>
    <p style="margin:0 0 16px; color:var(--text-dim); font-size:13px;">
        {{ $emails->total() }} {{ Str::plural('conversation', $emails->total()) }} waiting to come back.
        Snoozing is local to Zero: nothing changes on the mail server, so these are still in the inbox in other clients.
    </p>

    @if ($emails->isEmpty())
        <p class="empty-hint">Nothing snoozed.</p>
    @else
        <div class="att-list">
            @foreach ($emails as $email)
                <div class="att-row">
                    <div class="att-icon"><svg class="ic"><use href="#i-calendar"/></svg></div>

                    <div class="att-main">
                        <div class="att-name">
                            <a href="{{ route('inbox.show', $email) }}">{{ $email->subject ?: '(no subject)' }}</a>
                        </div>
                        <div class="att-meta">
                            <span>{{ $email->from_name ?: $email->from_address }}</span>
                            <span>
                                <span class="acct-dot" style="background:{{ $email->mailAccount?->color }}"></span>
                                {{ $email->mailAccount?->email_address }}
                            </span>
                            <span title="{{ $email->snoozed_until?->timezone(\App\Support\AppTimezone::name())->format('D j M Y, H:i') }}">
                                Back {{ $email->snoozed_until?->diffForHumans() }}
                            </span>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('inbox.unsnooze', $email) }}">
                        @csrf @method('DELETE')
                        <button class="btn sm ghost">Unsnooze</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div style="margin-top:16px;">{{ $emails->links() }}</div>
    @endif
</div>
@endsection
