@extends('layouts.app')

@section('title', 'Edit account')

@section('content')
    <div class="page-pad">
        <div class="form-card">
            <h2>Edit {{ $account->email_address }}</h2>

            <form method="POST" action="{{ route('accounts.update', $account) }}">
                @csrf
                @method('PUT')

                <div class="checkline" style="margin-bottom:14px;">
                    <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $account->is_active))>
                    <label for="is_active">Active (included in sync and unified inbox)</label>
                </div>

                <div class="field">
                    <label>Display name</label>
                    <input type="text" name="display_name" value="{{ old('display_name', $account->display_name) }}">
                </div>

                @if ($account->usesOAuth())
                    <p style="font-size:12.5px; color:var(--text-dim); margin:0 0 16px;">
                        This account connects via OAuth ({{ ucfirst($account->provider) }}) — its IMAP/SMTP settings and
                        credentials are managed automatically and can't be edited here. Remove and reconnect the account
                        if you need to re-authorize it.
                    </p>
                @else
                    <div class="field">
                        <label>Email address</label>
                        <input type="email" name="email_address" value="{{ old('email_address', $account->email_address) }}" required>
                    </div>

                    <div class="form-grid" style="margin-bottom:14px;">
                        <div class="field full" style="margin-bottom:0;">
                            <label>IMAP host</label>
                            <input type="text" name="imap_host" value="{{ old('imap_host', $account->imap_host) }}" required>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>Port</label>
                            <input type="number" name="imap_port" value="{{ old('imap_port', $account->imap_port) }}" required>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>Encryption</label>
                            <select name="imap_encryption">
                                <option value="ssl" @selected(old('imap_encryption', $account->imap_encryption) === 'ssl')>SSL</option>
                                <option value="tls" @selected(old('imap_encryption', $account->imap_encryption) === 'tls')>TLS</option>
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>IMAP username</label>
                            <input type="text" name="imap_username" value="{{ old('imap_username', $account->imap_username) }}" required>
                        </div>
                        <div class="field full" style="margin-bottom:0;">
                            <label>IMAP password</label>
                            <input type="password" name="imap_password" placeholder="Leave blank to keep the current password">
                        </div>
                    </div>

                    <hr style="border:none; border-top:1px solid var(--border); margin:0 0 14px;">

                    <div class="form-grid">
                        <div class="field full" style="margin-bottom:0;">
                            <label>SMTP host</label>
                            <input type="text" name="smtp_host" value="{{ old('smtp_host', $account->smtp_host) }}" required>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>Port</label>
                            <input type="number" name="smtp_port" value="{{ old('smtp_port', $account->smtp_port) }}" required>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>Encryption</label>
                            <select name="smtp_encryption">
                                <option value="tls" @selected(old('smtp_encryption', $account->smtp_encryption) === 'tls')>TLS</option>
                                <option value="ssl" @selected(old('smtp_encryption', $account->smtp_encryption) === 'ssl')>SSL</option>
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>SMTP username</label>
                            <input type="text" name="smtp_username" value="{{ old('smtp_username', $account->smtp_username) }}" required>
                        </div>
                        <div class="field full" style="margin-bottom:0;">
                            <label>SMTP password</label>
                            <input type="password" name="smtp_password" placeholder="Leave blank to keep the current password">
                        </div>
                    </div>
                @endif

                <div style="display:flex; align-items:center; gap:10px; margin-top:20px;">
                    <button class="btn primary">Save changes</button>
                    <a href="{{ route('accounts.index') }}" style="font-size:12.5px; color:var(--text-faint);">Cancel</a>
                </div>
            </form>

            <form method="POST" action="{{ route('accounts.sync', $account) }}" style="margin-top:16px;">
                @csrf
                <button class="btn ghost"><svg class="ic-sm"><use href="#i-refresh"/></svg>Sync now</button>
            </form>
        </div>
    </div>
@endsection
