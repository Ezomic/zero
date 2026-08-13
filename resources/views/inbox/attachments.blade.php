@extends('layouts.app')

@section('title', 'Attachments')

@section('content')
<div style="max-width:1100px; margin:0 auto; padding:22px; width:100%;">
    <a href="{{ route('inbox.index') }}" class="rp-back" style="margin-bottom:14px;">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>

    <h2 style="margin:0 0 4px; font-size:17px;">Attachments</h2>
    <p style="margin:0 0 16px; color:var(--text-dim); font-size:13px;">
        {{ $attachments->total() }} {{ Str::plural('file', $attachments->total()) }} across every account.
    </p>

    <form method="GET" action="{{ route('attachments.index') }}" class="att-filters">
        <div class="inbox-search" style="flex:1; min-width:200px;">
            <svg class="ic-sm"><use href="#i-search"/></svg>
            <input type="text" name="q" value="{{ $q }}" placeholder="Search filenames&hellip;">
        </div>

        <select name="type" onchange="this.form.submit()" class="tb-account" title="Filter by type">
            <option value="">All types</option>
            @foreach ($kinds as $value => $label)
                <option value="{{ $value }}" @selected($selectedKind === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="account" onchange="this.form.submit()" class="tb-account" title="Filter by account">
            <option value="">All accounts</option>
            @foreach ($accounts as $acc)
                <option value="{{ $acc->id }}" @selected($selectedAccountId === $acc->id)>{{ $acc->email_address }}</option>
            @endforeach
        </select>

        <button class="btn sm ghost">Filter</button>
    </form>

    @if ($attachments->isEmpty())
        <p class="empty-hint">Nothing matches.</p>
    @else
        <div class="att-list">
            @foreach ($attachments as $attachment)
                @php
                    $available = $present[$attachment->id] ?? false;
                    $kind = \App\Support\AttachmentKind::of($attachment->mime_type, $attachment->filename);
                    $email = $attachment->email;
                @endphp
                <div class="att-row {{ $available ? '' : 'att-gone' }}">
                    <div class="att-icon" title="{{ $kind ? $kinds[$kind] : 'File' }}">
                        <svg class="ic"><use href="#i-clip"/></svg>
                    </div>

                    <div class="att-main">
                        <div class="att-name">{{ $attachment->filename }}</div>
                        <div class="att-meta">
                            @if ($email)
                                <a href="{{ route('inbox.show', $email) }}" title="Open the message this came with">
                                    {{ Str::limit($email->subject ?: '(no subject)', 60) }}
                                </a>
                                <span>{{ $email->from_address }}</span>
                                <span>
                                    <span class="acct-dot" style="background:{{ $email->mailAccount?->color }}"></span>
                                    {{ $email->mailAccount?->email_address }}
                                </span>
                                <span>{{ $email->sent_at?->format('M j, Y') }}</span>
                            @endif
                            @if ($attachment->size_bytes)
                                <span>{{ round($attachment->size_bytes / 1024) }} KB</span>
                            @endif
                        </div>
                    </div>

                    @if ($available)
                        <a class="btn sm ghost" href="{{ route('attachments.download', $attachment) }}">Download</a>
                    @else
                        {{-- The row outlives the file if storage was cleared, so
                             say so here rather than offering a link that 404s. --}}
                        <span class="att-unavailable" title="The stored file is no longer on disk">Unavailable</span>
                    @endif
                </div>
            @endforeach
        </div>

        <div style="margin-top:16px;">{{ $attachments->links() }}</div>
    @endif
</div>
@endsection
