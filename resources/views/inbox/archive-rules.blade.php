@extends('layouts.app')

@section('title', 'Auto-archive')

@section('content')
<div style="max-width:820px; margin:0 auto; padding:22px; width:100%;">
    <a href="{{ route('inbox.index') }}" class="rp-back" style="margin-bottom:14px;">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>

    <h2 style="margin:0 0 4px; font-size:17px;">Auto-archive</h2>
    <p style="margin:0 0 16px; color:var(--text-dim); font-size:13px;">
        New inbox mail from these senders is archived when it arrives. Nothing is deleted and nothing changes on the mail server.
    </p>

    <form method="POST" action="{{ route('archiveRules.store') }}" class="card" style="padding:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:16px;">
        @csrf
        <select name="kind" aria-label="Match">
            <option value="address">Address</option>
            <option value="domain">Domain</option>
        </select>
        <input type="text" name="value" placeholder="noreply@example.com or example.com" required style="flex:1; min-width:200px;">
        <select name="mail_account_id" aria-label="Account">
            <option value="">All accounts</option>
            @foreach ($user->mailAccounts as $account)
                <option value="{{ $account->id }}">{{ $account->email_address }}</option>
            @endforeach
        </select>
        <button class="btn sm">Add rule</button>
    </form>

    @if ($rules->isEmpty())
        <p class="empty-hint">No rules yet. Add one here, or from a sender's page.</p>
    @else
        <div class="saved-manage" style="margin-bottom:24px;">
            @foreach ($rules as $rule)
                <div class="card saved-row">
                    <div class="saved-row-main">
                        <div class="saved-meta">
                            <strong>{{ $rule->value }}</strong>
                            <span>
                                {{ $rule->kind === 'domain' ? 'whole domain' : 'address' }}
                                &middot;
                                {{ $rule->mail_account_id ? ($user->mailAccounts->firstWhere('id', $rule->mail_account_id)?->email_address ?? 'account removed') : 'all accounts' }}
                            </span>
                        </div>
                    </div>
                    <div class="saved-row-actions">
                        <form method="POST" action="{{ route('archiveRules.destroy', $rule) }}"
                              onsubmit="return confirm('Remove this rule?')">
                            @csrf @method('DELETE')
                            <button class="icon-btn" title="Remove rule" style="color:var(--danger);">
                                <svg class="ic-sm"><use href="#i-trash"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <h3 style="margin:0 0 8px; font-size:14px;">Recently filed by rules</h3>
    @if ($filed->isEmpty())
        <p class="empty-hint">Nothing has been auto-archived yet.</p>
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
                        <form method="POST" action="{{ route('archiveRules.undo', $email) }}">
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
