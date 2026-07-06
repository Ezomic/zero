@extends('layouts.app')

@section('title', $email->subject)

@section('content')
    <div class="focus-wrap">
        <a href="{{ route('inbox.index') }}" class="focus-back"><svg class="ic-sm"><use href="#i-back"/></svg>Back to inbox</a>

        <div style="display:flex; justify-content:space-between; align-items:start; gap:16px;">
            <h1 class="focus-title">{{ $email->subject }}</h1>
            <div class="focus-actions">
                @if ($email->is_archived)
                    <form method="POST" action="{{ route('inbox.unarchive', $email) }}">
                        @csrf
                        <button class="icon-btn" title="Move to inbox"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
                    </form>
                @else
                    <form method="POST" action="{{ route('inbox.archive', $email) }}">
                        @csrf
                        <button class="icon-btn" title="Archive"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
                    </form>
                @endif
                <form method="POST" action="{{ route('inbox.markUnread', $email) }}">
                    @csrf
                    <button class="icon-btn" title="Mark unread"><svg class="ic-sm"><use href="#i-check"/></svg></button>
                </form>
                <form method="POST" action="{{ route('inbox.destroy', $email) }}" onsubmit="return confirm('Delete this conversation?')">
                    @csrf @method('DELETE')
                    <button class="icon-btn" title="Delete" style="color:var(--danger);"><svg class="ic-sm"><use href="#i-trash"/></svg></button>
                </form>
            </div>
        </div>

        @if (count($availableFolders) > 1)
            <form method="POST" action="{{ route('inbox.move', $email) }}" style="display:flex; gap:8px; margin:0 0 16px;">
                @csrf
                <select name="folder" required style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:12.5px;">
                    <option value="" disabled {{ $suggestedFolder ? '' : 'selected' }}>Move to&hellip;</option>
                    @foreach ($availableFolders as $f)
                        @unless ($f === $email->folder)
                            <option value="{{ $f }}" @selected($f === $suggestedFolder)>
                                {{ \App\Models\MailFolder::displayName($f) }}{{ $f === $suggestedFolder ? ' (suggested)' : '' }}
                            </option>
                        @endunless
                    @endforeach
                </select>
                <button class="btn sm ghost">Move</button>
            </form>
        @endif

        <div>
            @foreach ($messages as $message)
                @include('inbox._message', ['message' => $message])
            @endforeach
        </div>
    </div>
@endsection
