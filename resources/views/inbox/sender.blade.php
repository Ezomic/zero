@extends('layouts.app')

@section('title', $address)

@section('content')
<div style="max-width:1000px; margin:0 auto; padding:22px; width:100%;">
    <a href="{{ route('inbox.index') }}" class="rp-back" style="margin-bottom:14px;">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>

    <div class="card" style="padding:18px; margin-bottom:16px;">
        <h2 style="margin:0 0 6px; font-size:17px;">{{ $address }}</h2>
        <p style="margin:0; color:var(--text-dim); font-size:13px;">
            {{ $total }} message{{ $total === 1 ? '' : 's' }}
            @if ($unread > 0) &middot; {{ $unread }} unread @endif
            @if ($firstSeen && $lastSeen)
                &middot; {{ \Illuminate\Support\Carbon::parse($firstSeen)->format('M Y') }}
                to {{ \Illuminate\Support\Carbon::parse($lastSeen)->format('M Y') }}
            @endif
        </p>

        @if ($total > 0)
            <form method="POST" action="{{ route('sender.bulk') }}" style="margin-top:14px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                @csrf
                <input type="hidden" name="address" value="{{ $address }}">

                <button type="submit" name="action" value="archive" class="btn sm ghost">
                    <svg class="ic-sm"><use href="#i-archive"/></svg>Archive all {{ $total }}
                </button>
                <button type="submit" name="action" value="read" class="btn sm ghost">
                    <svg class="ic-sm"><use href="#i-check"/></svg>Mark all read
                </button>

                <span style="flex:1;"></span>

                {{-- Deleting a whole sender's history is asked about rather
                     than discovered afterwards, once it is more than a
                     handful. --}}
                @if ($total > $confirmAbove)
                    <label style="display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--text-dim);">
                        <input type="checkbox" name="confirmed" value="1">
                        Yes, delete all {{ $total }}
                    </label>
                @endif
                <button type="submit" name="action" value="delete" class="btn sm ghost" style="color:var(--danger);"
                        onsubmit="return true">
                    <svg class="ic-sm"><use href="#i-trash"/></svg>Delete all
                </button>
            </form>
        @endif
    </div>

    @if ($emails->isEmpty())
        <p style="color:var(--text-dim);">Nothing from this sender.</p>
    @else
        <div class="thread-list">
            @foreach ($emails as $email)
                @include('inbox._email_row', ['email' => $email, 'threadCounts' => collect()])
            @endforeach
        </div>

        <div style="margin-top:16px;">{{ $emails->links() }}</div>
    @endif
</div>
@endsection
