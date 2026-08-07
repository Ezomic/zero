<?php

namespace App\Http\Controllers;

use App\Actions\Mail\SummariseMirrorBacklogs;
use App\Concerns\InteractsWithCurrentUser;
use App\Jobs\SyncMailAccountJob;
use App\Models\MailAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MailAccountController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(SummariseMirrorBacklogs $summariseMirrorBacklogs): View
    {
        $accounts = $this->currentUser()->mailAccounts()->latest()->get();
        $backlogs = $summariseMirrorBacklogs->handle($accounts->modelKeys());

        return view('accounts.index', compact('accounts', 'backlogs'));
    }

    public function create(Request $request): View
    {
        $template = null;

        if ($request->filled('from')) {
            $template = $this->currentUser()
                ->mailAccounts()
                ->where('provider', MailAccount::PROVIDER_IMAP)
                ->find($request->integer('from'));
        }

        return view('accounts.create', compact('template'));
    }

    /**
     * Add a custom IMAP/SMTP account (Gmail/Outlook go through their OAuth
     * controllers instead).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
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

        $data = $this->stringKeyed($validated);

        /** @var MailAccount $account */
        $account = $this->currentUser()->mailAccounts()->create([
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
            $data = $this->stringKeyed($request->validate([
                'display_name' => ['nullable', 'string', 'max:255'],
            ]));

            $data['is_active'] = $request->boolean('is_active');

            $account->update($data);

            return redirect()->route('accounts.index')->with('status', 'Account updated.');
        }

        $validated = $request->validate([
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

        $data = $this->stringKeyed($validated);

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

        $account->markHealthy();

        SyncMailAccountJob::dispatch($account);

        return back()->with('status', 'Account re-enabled — sync queued.');
    }

    protected function authorizeOwnership(MailAccount $account): void
    {
        abort_unless($account->user_id === auth()->id(), 403);
    }

    /**
     * validate() is typed as returning array<array-key, mixed>, which is not
     * an update() payload at PHPStan level 10. Rekeying it once here is what
     * every caller needs, and keeps the branches from each rebuilding it (one
     * of them forgot to, which is how display_name went missing — ZERO-88).
     *
     * @param  mixed  $validated  the return of Request::validate()
     * @return array<string, mixed>
     */
    protected function stringKeyed($validated): array
    {
        $data = [];

        foreach (is_array($validated) ? $validated : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }
}
