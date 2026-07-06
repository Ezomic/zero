@extends('layouts.app')

@section('title', 'Edit account')

@section('content')
    <h1 class="text-xl font-semibold mb-4">Edit {{ $account->email_address }}</h1>

    <form method="POST" action="{{ route('accounts.update', $account) }}" class="bg-white border rounded p-6 max-w-xl space-y-4">
        @csrf
        @method('PUT')

        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $account->is_active))>
            <label for="is_active" class="text-sm font-medium">Active (included in sync and unified inbox)</label>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Display name</label>
            <input type="text" name="display_name" value="{{ old('display_name', $account->display_name) }}" class="w-full border rounded px-3 py-2">
        </div>

        @if ($account->usesOAuth())
            <p class="text-sm text-gray-500">
                This account connects via OAuth ({{ ucfirst($account->provider) }}) — its IMAP/SMTP settings and
                credentials are managed automatically and can't be edited here. Remove and reconnect the account
                if you need to re-authorize it.
            </p>
        @else
            <div>
                <label class="block text-sm font-medium mb-1">Email address</label>
                <input type="email" name="email_address" value="{{ old('email_address', $account->email_address) }}" class="w-full border rounded px-3 py-2" required>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1">IMAP host</label>
                    <input type="text" name="imap_host" value="{{ old('imap_host', $account->imap_host) }}" class="w-full border rounded px-3 py-2" required>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Port</label>
                    <input type="number" name="imap_port" value="{{ old('imap_port', $account->imap_port) }}" class="w-full border rounded px-3 py-2" required>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Encryption</label>
                    <select name="imap_encryption" class="w-full border rounded px-3 py-2">
                        <option value="ssl" @selected(old('imap_encryption', $account->imap_encryption) === 'ssl')>SSL</option>
                        <option value="tls" @selected(old('imap_encryption', $account->imap_encryption) === 'tls')>TLS</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1">IMAP username</label>
                    <input type="text" name="imap_username" value="{{ old('imap_username', $account->imap_username) }}" class="w-full border rounded px-3 py-2" required>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">IMAP password</label>
                <input type="password" name="imap_password" placeholder="Leave blank to keep the current password" class="w-full border rounded px-3 py-2">
            </div>

            <hr>

            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1">SMTP host</label>
                    <input type="text" name="smtp_host" value="{{ old('smtp_host', $account->smtp_host) }}" class="w-full border rounded px-3 py-2" required>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Port</label>
                    <input type="number" name="smtp_port" value="{{ old('smtp_port', $account->smtp_port) }}" class="w-full border rounded px-3 py-2" required>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Encryption</label>
                    <select name="smtp_encryption" class="w-full border rounded px-3 py-2">
                        <option value="tls" @selected(old('smtp_encryption', $account->smtp_encryption) === 'tls')>TLS</option>
                        <option value="ssl" @selected(old('smtp_encryption', $account->smtp_encryption) === 'ssl')>SSL</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1">SMTP username</label>
                    <input type="text" name="smtp_username" value="{{ old('smtp_username', $account->smtp_username) }}" class="w-full border rounded px-3 py-2" required>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">SMTP password</label>
                <input type="password" name="smtp_password" placeholder="Leave blank to keep the current password" class="w-full border rounded px-3 py-2">
            </div>
        @endif

        <div class="flex items-center gap-2">
            <button class="px-4 py-2 rounded bg-blue-600 text-white">Save changes</button>
            <a href="{{ route('accounts.index') }}" class="text-sm text-gray-500">Cancel</a>
        </div>
    </form>

    <form method="POST" action="{{ route('accounts.sync', $account) }}" class="mt-4">
        @csrf
        <button class="px-4 py-2 rounded border">Sync now</button>
    </form>
@endsection
