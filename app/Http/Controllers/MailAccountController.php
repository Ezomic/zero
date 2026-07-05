<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MailAccountController extends Controller
{
    public function index(): View
    {
        $accounts = auth()->user()->mailAccounts()->latest()->get();

        return view('accounts.index', compact('accounts'));
    }

    public function create(): View
    {
        return view('accounts.create');
    }

    /**
     * Add a custom IMAP/SMTP account (Gmail/Outlook go through their OAuth
     * controllers instead).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email_address' => ['required', 'email'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'imap_host' => ['required', 'string'],
            'imap_port' => ['required', 'integer'],
            'imap_encryption' => ['nullable', 'in:ssl,tls'],
            'imap_username' => ['required', 'string'],
            'imap_password' => ['required', 'string'],
            'smtp_host' => ['required', 'string'],
            'smtp_port' => ['required', 'integer'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls'],
            'smtp_username' => ['required', 'string'],
            'smtp_password' => ['required', 'string'],
        ]);

        /** @var MailAccount $account */
        $account = auth()->user()->mailAccounts()->create([
            ...$data,
            'provider' => MailAccount::PROVIDER_IMAP,
            'is_active' => true,
        ]);

        SyncMailAccountJob::dispatch($account);

        return redirect()->route('accounts.index')->with('status', 'Account added — initial sync queued.');
    }

    public function edit(MailAccount $account): View
    {
        $this->authorizeOwnership($account);

        return view('accounts.edit', compact('account'));
    }

    /**
     * OAuth accounts (Gmail/Outlook) only expose display name/active state —
     * their IMAP/SMTP settings and credentials are provider-managed.
     * Custom IMAP accounts can update everything; passwords are optional so
     * leaving them blank keeps the current encrypted value (e.g. fixing a
     * Google "application-specific password required" error just means
     * updating the password, not re-entering every other field).
     */
    public function update(Request $request, MailAccount $account): RedirectResponse
    {
        $this->authorizeOwnership($account);

        if ($account->usesOAuth()) {
            $data = $request->validate([
                'display_name' => ['nullable', 'string', 'max:255'],
            ]);
            $data['is_active'] = $request->boolean('is_active');

            $account->update($data);

            return redirect()->route('accounts.index')->with('status', 'Account updated.');
        }

        $data = $request->validate([
            'email_address' => ['required', 'email'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'imap_host' => ['required', 'string'],
            'imap_port' => ['required', 'integer'],
            'imap_encryption' => ['nullable', 'in:ssl,tls'],
            'imap_username' => ['required', 'string'],
            'imap_password' => ['nullable', 'string'],
            'smtp_host' => ['required', 'string'],
            'smtp_port' => ['required', 'integer'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls'],
            'smtp_username' => ['required', 'string'],
            'smtp_password' => ['nullable', 'string'],
        ]);

        if (empty($data['imap_password'])) {
            unset($data['imap_password']);
        }

        if (empty($data['smtp_password'])) {
            unset($data['smtp_password']);
        }

        $data['is_active'] = $request->boolean('is_active');

        $account->update($data);

        return redirect()->route('accounts.index')->with('status', 'Account updated — click "Sync now" to test the new settings.');
    }

    public function destroy(MailAccount $account): RedirectResponse
    {
        $this->authorizeOwnership($account);
        $account->delete();

        return redirect()->route('accounts.index')->with('status', 'Account removed.');
    }

    public function sync(MailAccount $account): RedirectResponse
    {
        $this->authorizeOwnership($account);
        SyncMailAccountJob::dispatch($account);

        return back()->with('status', 'Sync queued.');
    }

    public function reenable(MailAccount $account): RedirectResponse
    {
        $this->authorizeOwnership($account);

        $account->update([
            'is_active' => true,
            'sync_status' => 'idle',
            'sync_status_since' => now(),
            'sync_error' => null,
        ]);

        SyncMailAccountJob::dispatch($account);

        return back()->with('status', 'Account re-enabled — sync queued.');
    }

    protected function authorizeOwnership(MailAccount $account): void
    {
        abort_unless($account->user_id === auth()->id(), 403);
    }
}
