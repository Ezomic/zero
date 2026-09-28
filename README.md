# Multi-Account Mail Client (Laravel)

Unified inbox + send for Gmail, Outlook/Hotmail (OAuth2) and any custom
IMAP/SMTP account (app password). This package contains the app-specific
files only — merge them into a fresh Laravel install.

## 1. Create the base Laravel app

```bash
composer create-project laravel/laravel zero
cd zero
```

Copy every file/folder from this package into the new project, matching
paths exactly (app/, database/migrations/, resources/views/, routes/web.php,
README setup notes). If `routes/web.php` already exists, replace it with the
one provided here.

Also apply the two `.snippet.php` files by hand:
- `app/Models/User.php.snippet.php` → add the `mailAccounts()` relation to
  your real `app/Models/User.php`, then delete the snippet file.
- `routes/console-scheduling.snippet.php` → add the `Schedule::command(...)`
  line to `routes/console.php` (Laravel 11+) or `App\Console\Kernel::schedule()`
  (Laravel 10-), then delete the snippet.
- `config/services.additions.php` → merge these entries into
  `config/services.php`, then delete the file.

## 2. Install dependencies

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade   # gives you /login, /register, auth middleware
composer require webklex/laravel-imap
composer require laravel/socialite
composer require socialiteproviders/microsoft-graph
npm install && npm run build
```

Register the Microsoft Graph Socialite driver — add to
`app/Providers/AppServiceProvider.php` `boot()`:

```php
use SocialiteProviders\Manager\SocialiteWasCalled;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\MicrosoftGraph\MicrosoftGraphExtendSocialite;

Event::listen(function (SocialiteWasCalled $event) {
    $event->extendSocialite('microsoft-graph', MicrosoftGraphExtendSocialite::class);
});
```

## 3. Environment variables

Add to `.env`:

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback

MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_REDIRECT_URI=http://localhost:8000/auth/microsoft/callback

QUEUE_CONNECTION=database
```

Run `php artisan queue:table && php artisan queue:batches-table` if you
haven't already set up the queue tables, then migrate.

## 4. Register OAuth apps

**Google (for Gmail)**
1. Go to console.cloud.google.com → create/select a project.
2. APIs & Services → Enable the **Gmail API**.
3. OAuth consent screen → set up (External is fine for personal use; add
   your own email as a test user while in "Testing" mode).
4. Credentials → Create OAuth client ID → Web application.
5. Authorized redirect URI: `http://localhost:8000/auth/google/callback`
   (and your production URL later).
6. Copy Client ID / Secret into `.env`.

**Microsoft (for Outlook/Hotmail)**
1. Go to portal.azure.com → Azure Active Directory → App registrations →
   New registration.
2. Supported account types: "Accounts in any organizational directory and
   personal Microsoft accounts" (needed for hotmail.com/outlook.com/live.com).
3. Redirect URI (Web): `http://localhost:8000/auth/microsoft/callback`
4. Certificates & secrets → New client secret → copy the value.
5. API permissions → Add: `Mail.ReadWrite`, `Mail.Send`, `offline_access`,
   `openid`, `email`, `profile` (Microsoft Graph, delegated).
6. Copy Application (client) ID / secret into `.env`.

## 5. Migrate and run

```bash
php artisan migrate
php artisan storage:link
php artisan serve
php artisan queue:work        # in a second terminal — processes sync/send jobs
```

Then visit `/register`, create an account, go to **Accounts** and connect
Gmail/Outlook or add a custom IMAP/SMTP mailbox.

## 6. Keep mailboxes syncing

The scheduler dispatches `mail:sync` every 5 minutes, which queues a sync job
per active account. Both the scheduler and the queue worker must be running.

### macOS (local dev) — launchd

Two launchd agents are set up in `~/Library/LaunchAgents/`:

- `nl.thijssensoftware.zero.scheduler.plist` — runs `schedule:work`
- `nl.thijssensoftware.zero.queue.plist` — runs `queue:work`

They start automatically at login and restart on crash. Logs:

```bash
tail -f ~/Library/Logs/zero-scheduler.log
tail -f ~/Library/Logs/zero-queue.log
```

Useful commands:

```bash
# Check both are running (should show two PIDs)
launchctl list | grep zero

# Restart after deploying code changes
launchctl kickstart -k gui/$(id -u)/nl.thijssensoftware.zero.scheduler
launchctl kickstart -k gui/$(id -u)/nl.thijssensoftware.zero.queue

# Load/unload manually
launchctl load ~/Library/LaunchAgents/nl.thijssensoftware.zero.scheduler.plist
launchctl unload ~/Library/LaunchAgents/nl.thijssensoftware.zero.scheduler.plist
```

### Production: systemd units from Ezomic/infra

Nothing on the server is started by hand. zero's entry in `Ezomic/infra`
(`ansible/group_vars/all/apps.yml`) generates one systemd unit per process:
`zero-schedule.timer` runs `schedule:run` every minute, and the queue workers,
`zero-reverb` and each `zero-idle-{id}` run as services that systemd restarts
when they exit. `app-deploy` restarts those services after every release.

You can also trigger a manual sync anytime from the Accounts page, or:

```bash
php artisan mail:sync --account=1
```

## How it's built

- **Gmail / Outlook**: OAuth2 via Laravel Socialite. Refresh tokens stored
  encrypted; `OAuthTokenRefresher` exchanges them for fresh access tokens as
  needed. **Reading** uses IMAP with XOAUTH2 (`webklex/laravel-imap`).
  **Sending** uses the Gmail API / Microsoft Graph `sendMail` directly
  (more reliable than SMTP XOAUTH2 SASL).
- **Custom accounts**: standard IMAP (reading) and SMTP (sending) with
  encrypted stored credentials — works with cPanel mailboxes, self-hosted
  mail servers, Zoho, Fastmail, etc.
- All account passwords/tokens use Laravel's `encrypted` Eloquent cast, so
  they're encrypted at rest with your `APP_KEY`.

## Known limitations / next steps

- No reply/forward threading yet — `ComposeController` sends new messages
  only.
- No attachment upload on compose yet — inbound attachments are stored and
  listed, but outbound attachments would need a file input + `Email::attach()`.
- Gmail's OAuth consent screen stays in "Testing" mode (100 user cap) until
  you submit for verification — fine for personal/internal use.

## Adding an idle agent for a new account

Adding a MailAccount does **not** automatically start a watcher for it, so
until you run this the account only syncs on the 5-minute schedule:

```bash
php artisan mail:idle:provision {id}
```

Locally this writes the launchd plist and loads it. On production it prints
the `idle-{id}` worker entry to add to zero's entry in `Ezomic/infra`
`ansible/group_vars/all/apps.yml`, plus the `ansible-playbook site.yml --tags apps`
command that renders and starts `zero-idle-{id}.service`. It never edits the
server itself: the units are generated from the playbook, which the app cannot
change.

It refuses Outlook accounts (Graph has no IDLE equivalent) and inactive ones,
and re-running it for an account that already has a plist is a no-op.

Add the new agent names to the `AGENTS` array in `~/bin/workers` and add
rotation entries to `~/Library/Logs/newsyslog-workers.conf`.

### Still to provision

`robbin_thijssen@hotmail.nl` (account 7) is inactive, so it needs its auth
fixed before it can be provisioned. `ezomic@gmail.com` (account 8) is active
and has no watcher yet.

### Removing an idle agent when an account is deleted

Deleting a MailAccount does **not** automatically stop its watcher — run this
by hand right after, on whichever host runs it:

```bash
php artisan mail:idle:deprovision {id}
```

Locally this unloads and removes the launchd plist for you. On production it
prints the steps instead: remove the `idle-{id}` worker from zero's entry in
`ansible/group_vars/all/apps.yml`, run `ansible-playbook site.yml --tags apps`,
then disable and delete the leftover `zero-idle-{id}.service` on the server,
because the playbook renders units but never removes them.

### Outlook OAuth (account 7 — robbin_thijssen@hotmail.nl)

Microsoft deprecated basic IMAP auth. The OAuth flow is already built
(`auth.microsoft.redirect` / `MicrosoftOAuthController`). The account just
needs re-connecting via OAuth.

The most likely reason previous attempts failed: the Azure app registration's
redirect URI is set to `http://localhost:8000/auth/microsoft/callback` but the
local app runs at `http://zero.test`. Add `http://zero.test/auth/microsoft/callback`
as an allowed redirect URI in the Azure portal (portal.azure.com →
App registrations → your app → Authentication → Redirect URIs), then click
"Connect Outlook / Hotmail" on the accounts page.
