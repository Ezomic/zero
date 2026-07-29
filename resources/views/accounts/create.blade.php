@extends('layouts.app')

@section('title', 'Add custom account')

@section('content')
    <div class="page-pad">
        <div class="form-card">
            <h2>Add a custom IMAP/SMTP account</h2>
            @if ($template)
                <p class="lead">Server settings copied from <strong>{{ $template->email_address }}</strong>. Fill in the email and password.</p>
            @else
                <p class="lead">Works with cPanel mailboxes, self-hosted servers, Zoho, Fastmail, and more.</p>
            @endif

            <form method="POST" action="{{ route('accounts.store') }}">
                @csrf

                <div class="field">
                    <label>Email address</label>
                    <input type="email" name="email_address" value="{{ old('email_address') }}" required autofocus>
                </div>

                <div class="field">
                    <label>Display name</label>
                    <input type="text" name="display_name" value="{{ old('display_name') }}">
                </div>

                <div class="form-grid" style="margin-bottom:14px;">
                    <div class="field full" style="margin-bottom:0;">
                        <label>IMAP host</label>
                        <input type="text" name="imap_host" placeholder="mail.example.com" value="{{ old('imap_host', $template?->imap_host) }}" required>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>Port</label>
                        <input type="number" name="imap_port" placeholder="993" value="{{ old('imap_port', $template?->imap_port ?? 993) }}" required>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>Encryption</label>
                        <select name="imap_encryption">
                            <option value="ssl" @selected(old('imap_encryption', $template?->imap_encryption ?? 'ssl') === 'ssl')>SSL</option>
                            <option value="tls" @selected(old('imap_encryption', $template?->imap_encryption) === 'tls')>TLS</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>IMAP username</label>
                        <input type="text" name="imap_username" value="{{ old('imap_username', $template?->imap_username) }}" required>
                    </div>
                    <div class="field full" style="margin-bottom:0;">
                        <label>IMAP password</label>
                        <input type="password" name="imap_password" required>
                    </div>
                </div>

                <hr style="border:none; border-top:1px solid var(--border); margin:0 0 14px;">

                <div class="form-grid">
                    <div class="field full" style="margin-bottom:0;">
                        <label>SMTP host</label>
                        <input type="text" name="smtp_host" placeholder="mail.example.com" value="{{ old('smtp_host', $template?->smtp_host) }}" required>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>Port</label>
                        <input type="number" name="smtp_port" placeholder="587" value="{{ old('smtp_port', $template?->smtp_port ?? 587) }}" required>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>Encryption</label>
                        <select name="smtp_encryption">
                            <option value="tls" @selected(old('smtp_encryption', $template?->smtp_encryption ?? 'tls') === 'tls')>TLS</option>
                            <option value="ssl" @selected(old('smtp_encryption', $template?->smtp_encryption) === 'ssl')>SSL</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>SMTP username</label>
                        <input type="text" name="smtp_username" value="{{ old('smtp_username', $template?->smtp_username) }}" required>
                    </div>
                    <div class="field full" style="margin-bottom:0;">
                        <label>SMTP password</label>
                        <input type="password" name="smtp_password" required>
                    </div>
                </div>

                <button class="btn primary" style="margin-top:20px;">Add account</button>
            </form>
        </div>
    </div>
@endsection
