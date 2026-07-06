# Multi-Account Mail Client (Laravel)

Unified inbox + send for Gmail, Outlook/Hotmail (OAuth2) and any custom
IMAP/SMTP account (app password). This package contains the app-specific
files only — merge them into a fresh Laravel install.

## 1. Create the base Laravel app

```bash
composer create-project laravel/laravel mail-app
cd mail-app
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

- `nl.thijssensoftware.mailapp.scheduler.plist` — runs `schedule:work`
- `nl.thijssensoftware.mailapp.queue.plist` — runs `queue:work`

They start automatically at login and restart on crash. Logs:

```bash
tail -f ~/Library/Logs/mailapp-scheduler.log
tail -f ~/Library/Logs/mailapp-queue.log
```

Useful commands:

```bash
# Check both are running (should show two PIDs)
launchctl list | grep mailapp

# Restart after deploying code changes
launchctl kickstart -k gui/$(id -u)/nl.thijssensoftware.mailapp.scheduler
launchctl kickstart -k gui/$(id -u)/nl.thijssensoftware.mailapp.queue

# Load/unload manually
launchctl load ~/Library/LaunchAgents/nl.thijssensoftware.mailapp.scheduler.plist
launchctl unload ~/Library/LaunchAgents/nl.thijssensoftware.mailapp.scheduler.plist
```

### Production — Supervisor + cron

Run the scheduler via cron and the queue worker under Supervisor:

```
* * * * * php artisan schedule:run
```

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

## TODOs

### IMAP IDLE agents for accounts 7 and 8

Once auth is fixed for `robbin_thijssen@hotmail.nl` (account 7) and
`ezomic@gmail.com` (account 8), create a launchd agent for each:

```bash
# Copy nl.thijssensoftware.mailapp.idle.6.plist, change the label and
# the account argument (6 → 7, 6 → 8), then:
launchctl load ~/Library/LaunchAgents/nl.thijssensoftware.mailapp.idle.7.plist
launchctl load ~/Library/LaunchAgents/nl.thijssensoftware.mailapp.idle.8.plist
```

Add the new agent names to the `AGENTS` array in `~/bin/workers` and add
rotation entries to `~/Library/Logs/newsyslog-workers.conf`.

### Outlook OAuth (account 7 — robbin_thijssen@hotmail.nl)

Microsoft deprecated basic IMAP auth. The OAuth flow is already built
(`auth.microsoft.redirect` / `MicrosoftOAuthController`). The account just
needs re-connecting via OAuth.

The most likely reason previous attempts failed: the Azure app registration's
redirect URI is set to `http://localhost:8000/auth/microsoft/callback` but the
local app runs at `http://mail-app.test`. Add `http://mail-app.test/auth/microsoft/callback`
as an allowed redirect URI in the Azure portal (portal.azure.com →
App registrations → your app → Authentication → Redirect URIs), then click
"Connect Outlook / Hotmail" on the accounts page.
