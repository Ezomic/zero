@extends('layouts.app')

@section('title', 'Rules')

@php
    $fieldLabels = ['from' => 'Sender', 'to' => 'Recipient', 'subject' => 'Subject'];
    $actionLabels = ['archive' => 'Archive', 'star' => 'Star', 'mark_read' => 'Mark as read', 'file' => 'File into'];
@endphp

@section('content')
<div style="max-width:820px; margin:0 auto; padding:16px; width:100%;">
    <a href="{{ route('inbox.index') }}" class="rp-back" style="margin-bottom:14px;">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>

    <h2 style="margin:0 0 4px; font-size:17px;">Rules</h2>
    <p style="margin:0 0 16px; color:var(--text-dim); font-size:13px;">
        Rules run on new inbox mail as it arrives, from the top of the list down. Every rule that matches applies, so one message can be starred and filed; if two rules name a folder, the higher one wins. Nothing is ever deleted. Mail you move or archive by hand afterwards is left alone.
    </p>

    @if ($errors->any())
        <div class="card" style="padding:10px 14px; margin-bottom:12px; border-color:var(--danger); color:var(--danger); font-size:13px;">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('mailRules.store') }}" class="card rule-form"
          x-data="{ field: '{{ old('field', 'from') }}', action: '{{ old('action', 'archive') }}', account: '{{ old('mail_account_id') }}', folders: @js($folders) }">
        @csrf
        <div class="rule-form-row">
            <span class="rule-form-label">If</span>
            <select name="field" x-model="field" aria-label="Look at">
                <option value="from">Sender</option>
                <option value="to">Recipient (To or Cc)</option>
                <option value="subject">Subject</option>
            </select>
            <select name="operator" aria-label="Match">
                <option value="contains" @selected(old('operator') === 'contains')>contains</option>
                <option value="is" @selected(old('operator') === 'is')>is exactly</option>
                <option value="domain" x-bind:disabled="field === 'subject'" @selected(old('operator') === 'domain')>has the domain</option>
            </select>
            <input type="text" name="value" value="{{ old('value') }}" maxlength="255" required placeholder="Plain text, any case" aria-label="Text to match">
        </div>
        <div class="rule-form-row">
            <span class="rule-form-label">Then</span>
            <select name="action" x-model="action" aria-label="Action">
                <option value="archive">Archive</option>
                <option value="star">Star</option>
                <option value="mark_read">Mark as read</option>
                <option value="file">File into a folder</option>
            </select>
            <select name="mail_account_id" x-model="account" aria-label="Account">
                <option value="" x-bind:disabled="action === 'file'">All accounts</option>
                @foreach ($user->mailAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->email_address }}</option>
                @endforeach
            </select>
            <select name="folder" x-show="action === 'file'" x-bind:disabled="action !== 'file'" aria-label="Folder">
                <template x-for="name in (folders[account] || [])" :key="name">
                    <option :value="name" x-text="name"></option>
                </template>
            </select>
        </div>
        <div class="rule-form-row">
            <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text-dim);">
                <input type="checkbox" name="stop_on_match" value="1"> Stop here: skip the rules below when this one matches
            </label>
            <button class="btn primary sm" style="margin-left:auto;">Add rule</button>
        </div>
    </form>

    @if ($rules->isEmpty())
        <p class="empty-hint">No rules yet. Add one above, or from a sender's page.</p>
    @else
        <div class="saved-manage" style="margin-bottom:24px;">
            @foreach ($rules as $rule)
                @php
                    $accountName = $rule->mail_account_id
                        ? ($user->mailAccounts->firstWhere('id', $rule->mail_account_id)?->email_address ?? 'account removed')
                        : 'all accounts';
                    $folderMissing = $rule->action === 'file' && ! in_array($rule->folder, $folders[$rule->mail_account_id] ?? [], true);
                @endphp
                <div class="card rule-row">
                    <div class="rule-pos">{{ $loop->iteration }}</div>
                    <div class="rule-main">
                        <div class="rule-sentence">
                            {{ $fieldLabels[$rule->field] ?? $rule->field }}
                            {{ $rule->operator === 'domain' ? 'domain is' : ($rule->operator === 'is' ? 'is' : 'contains') }}
                            <strong>{{ $rule->value }}</strong>
                            <span class="rule-arrow">&rarr;</span>
                            {{ $actionLabels[$rule->action] ?? $rule->action }}@if ($rule->action === 'file') <strong>{{ $rule->folder }}</strong>@endif
                        </div>
                        <div class="saved-meta">
                            <span>{{ $accountName }}</span>
                            @if ($rule->stop_on_match)<span>stops further rules</span>@endif
                            @if ($folderMissing)<span style="color:var(--danger);">folder not found, rule is paused</span>@endif
                        </div>
                    </div>
                    <div class="rule-actions">
                        <form method="POST" action="{{ route('mailRules.move', $rule) }}">
                            @csrf
                            <input type="hidden" name="direction" value="up">
                            <button class="icon-btn" title="Move up" aria-label="Move up" @disabled($loop->first)>&uarr;</button>
                        </form>
                        <form method="POST" action="{{ route('mailRules.move', $rule) }}">
                            @csrf
                            <input type="hidden" name="direction" value="down">
                            <button class="icon-btn" title="Move down" aria-label="Move down" @disabled($loop->last)>&darr;</button>
                        </form>
                        <a class="btn sm ghost" href="{{ route('mailRules.preview', $rule) }}">Apply to existing mail</a>
                        <form method="POST" action="{{ route('mailRules.destroy', $rule) }}"
                              onsubmit="return confirm('Remove this rule?')">
                            @csrf @method('DELETE')
                            <button class="icon-btn" title="Remove rule" aria-label="Remove rule" style="color:var(--danger);">
                                <svg class="ic-sm"><use href="#i-trash"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <h3 style="margin:0 0 8px; font-size:14px;">Recently archived by rules</h3>
    @if ($filed->isEmpty())
        <p class="empty-hint">Nothing has been archived by a rule yet.</p>
    @else
        <div class="saved-manage">
            @foreach ($filed as $email)
                <div class="card saved-row">
                    <div class="saved-row-main">
                        <div class="saved-meta">
                            <a href="{{ route('inbox.show', $email) }}">{{ $email->subject }}</a>
                            <span>{{ $email->from_address }} &middot; {{ $email->sent_at?->format('j M H:i') }}</span>
                        </div>
                    </div>
                    <div class="saved-row-actions">
                        <form method="POST" action="{{ route('mailRules.undo', $email) }}">
                            @csrf
                            <button class="btn sm ghost">Undo</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
