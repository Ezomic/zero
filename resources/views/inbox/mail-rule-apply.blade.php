@extends('layouts.app')

@section('title', 'Apply rule')

@php
    $fieldLabels = ['from' => 'Sender', 'to' => 'Recipient', 'subject' => 'Subject'];
    $actionLabels = ['archive' => 'archive', 'star' => 'star', 'mark_read' => 'mark as read', 'file' => 'file into '.$rule->folder];
@endphp

@section('content')
<div style="max-width:620px; margin:0 auto; padding:16px; width:100%;">
    <a href="{{ route('mailRules.index') }}" class="rp-back" style="margin-bottom:14px;">
        <svg class="ic-sm"><use href="#i-back"/></svg> Rules
    </a>

    <h2 style="margin:0 0 4px; font-size:17px;">Apply to existing mail</h2>
    <p style="margin:0 0 16px; color:var(--text-dim); font-size:13px;">
        {{ $fieldLabels[$rule->field] ?? $rule->field }}
        {{ $rule->operator === 'domain' ? 'domain is' : ($rule->operator === 'is' ? 'is' : 'contains') }}
        <strong>{{ $rule->value }}</strong>, then {{ $actionLabels[$rule->action] ?? $rule->action }}.
    </p>

    @if (session('status'))
        <div class="card" style="padding:10px 14px; margin-bottom:12px; font-size:13px;">{{ session('status') }}</div>
    @endif

    <div class="card" style="padding:16px; margin-bottom:16px;">
        @if ($window->scanned === 0)
            <p style="margin:0; font-size:14px;">There is no inbox mail left for this rule to change.</p>
        @else
            <p style="margin:0 0 6px; font-size:15px;">
                <strong>{{ $window->matched }} of the {{ $window->scanned }} messages</strong> in this stretch match.
            </p>
            <p style="margin:0; font-size:12.5px; color:var(--text-dim);">
                Only inbox mail the rule would still change is counted, newest first{{ $window->oldest ? ', back to '.$window->oldest->format('j M Y') : '' }}.
                Nothing is deleted. Only this rule is applied: the rules above it and any stop setting are not considered.
                @if ($window->nextBefore !== null) Older mail is checked in the next stretch.@endif
            </p>
        @endif
    </div>

    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        @if ($window->matched > 0)
            <form method="POST" action="{{ route('mailRules.apply', $rule) }}">
                @csrf
                @if ($before !== null)<input type="hidden" name="before" value="{{ $before }}">@endif
                <button class="btn primary">Apply to {{ $window->matched }} {{ $window->matched === 1 ? 'message' : 'messages' }}</button>
            </form>
        @elseif ($window->nextBefore !== null)
            <a class="btn" href="{{ route('mailRules.preview', [$rule, 'before' => $window->nextBefore]) }}">Check older mail</a>
        @endif
        <a class="btn ghost" href="{{ route('mailRules.index') }}">Back to rules</a>
    </div>
</div>
@endsection
