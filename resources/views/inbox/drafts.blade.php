@extends('layouts.app')

@section('title', 'Drafts')

@section('content')
    <div class="list-header">
        <div class="list-header-top">
            <h1>Drafts</h1>
        </div>
    </div>

    <div class="thread-list">
        @forelse ($drafts as $draft)
            @php
                $draftInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $draft->to_addresses ?: 'draft'), 0, 2)) ?: '??';
            @endphp
            <div class="trow">
                <div class="avatar" style="background:{{ $draft->mailAccount?->color ?? '#9098AC' }}">{{ $draftInitials }}</div>
                <a href="{{ route('compose.create', ['draft' => $draft->id]) }}" class="trow-main">
                    <div class="trow-top">
                        <span class="trow-from">To: {{ $draft->to_addresses ?: '(no recipient yet)' }}</span>
                        <span class="trow-time">{{ $draft->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="trow-subject">
                        <span class="pill warn" style="margin-right:6px;">Draft</span>{{ $draft->subject ?: '(no subject)' }}
                    </div>
                    @if ($draft->body)
                        <div class="trow-snippet">{{ Str::limit(strip_tags($draft->body), 120) }}</div>
                    @endif
                </a>
                <form method="POST" action="{{ route('drafts.destroy', $draft) }}" onsubmit="return confirm('Discard this draft?')">
                    @csrf @method('DELETE')
                    <button class="btn sm ghost danger">Discard</button>
                </form>
            </div>
        @empty
            <p class="empty-hint">No saved drafts.</p>
        @endforelse
    </div>
@endsection
