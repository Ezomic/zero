# mail-app — Project context for Claude

## What this is

A self-hosted multi-account mail client for personal use. Supports Gmail (OAuth2),
Outlook/Hotmail (OAuth2), and any custom IMAP/SMTP account. Unified inbox with
thread grouping, full-text search, real-time notifications, and IMAP IDLE for
instant new-mail delivery.

## Stack

- **PHP 8.4, Laravel 13** — Blade + Alpine.js, no Inertia, no Vue, no Livewire
- **SQLite** — single file at `database/database.sqlite`
- **Tailwind CSS v4** — utility classes in Blade views
- **webklex/laravel-imap** — IMAP reading and IDLE
- **Laravel Reverb** — WebSocket server for real-time browser updates
- **Laravel Echo + pusher-js** — browser WebSocket client
- **Laravel Socialite** — OAuth2 for Gmail and Outlook
- **Symfony Mailer** — SMTP sending for custom accounts

## Running locally

App runs under **Herd** at `mail-app.test`. No `php artisan serve` needed.

```bash
php artisan migrate
php artisan storage:link     # once after fresh install
php artisan test             # 31 tests, all should pass
```

All background processes run as **launchd agents** — no manual terminal needed.

```bash
~/bin/workers status         # check all agents at a glance
~/bin/workers restart mailapp  # restart after a deploy
~/bin/workers logs mailapp   # tail all mailapp logs
workers rotate               # rotate log files now
```

### Active launchd agents

| Agent label | What it runs |
|---|---|
| `nl.thijssensoftware.mailapp.scheduler` | `php artisan schedule:work` — dispatches `mail:sync` every 5 min |
| `nl.thijssensoftware.mailapp.queue` | `php artisan queue:work --max-time=3600` — processes sync/send/flag jobs |
| `nl.thijssensoftware.mailapp.reverb` | `php artisan reverb:start` — WebSocket server on port 8080 |
| `nl.thijssensoftware.mailapp.idle.6` | `php artisan mail:idle 6` — IMAP IDLE for info@thijssensoftware.nl |

Plist files live in `~/Library/LaunchAgents/`. Logs in `~/Library/Logs/` as
`mailapp-{agent}.log`, capped at 5 MB / 5 files by `~/Library/Logs/newsyslog-workers.conf`.

## Routes

### Auth
| Route | Notes |
|---|---|
| `GET|POST /login` | Standard Breeze login |
| `GET /auth/google/redirect` | Start Gmail OAuth flow |
| `GET /auth/google/callback` | Gmail OAuth callback — creates/updates MailAccount |
| `GET /auth/microsoft/redirect` | Start Outlook OAuth flow |
| `GET /auth/microsoft/callback` | Outlook OAuth callback — creates/updates MailAccount |

### Inbox (all behind `auth`)
| Route | Name | Notes |
|---|---|---|
| `GET /` | `inbox.index` | Unified inbox, thread-collapsed, paginated 25/page |
| `GET /emails/{email}` | `inbox.show` | Full thread view; lazily fetches bodies on open |
| `POST /emails/{email}/archive` | `inbox.archive` | Archives whole thread |
| `POST /emails/{email}/mark-unread` | `inbox.markUnread` | Marks thread unread |
| `POST /emails/{email}/move` | `inbox.move` | Moves thread to another folder |
| `DELETE /emails/{email}` | `inbox.destroy` | Soft-deletes thread, queues IMAP expunge |
| `POST /emails/bulk` | `inbox.bulk` | Bulk archive/delete/read/unread |
| `GET /api/unread-count` | `inbox.unreadCount` | Polled by sidebar badge |
| `GET /api/new-emails` | `inbox.newEmails` | Polled by live inbox for new rows |
| `GET /triage` | `triage.index` | Triage view (skip/move/delete one at a time) |
| `GET /compose` | `compose.create` | Compose new message |
| `POST /compose` | `compose.store` | Send + write to IMAP Sent folder |
| `GET /drafts` | `drafts.index` | Draft list |
| `POST /drafts` | `drafts.autosave` | Auto-save draft from compose |
| `GET /contacts/search` | `contacts.search` | Autocomplete for To/CC fields |

### Accounts
| Route | Name | Notes |
|---|---|---|
| `GET /accounts` | `accounts.index` | Lists all accounts with sync status |
| `GET /accounts/create` | `accounts.create` | Custom IMAP/SMTP form |
| `POST /accounts` | `accounts.store` | Add custom account |
| `GET /accounts/{account}/edit` | `accounts.edit` | Edit account settings |
| `PUT /accounts/{account}` | `accounts.update` | Save account changes |
| `DELETE /accounts/{account}` | `accounts.destroy` | Remove account |
| `POST /accounts/{account}/sync` | `accounts.sync` | Manual sync trigger |
| `POST /accounts/{account}/reenable` | `accounts.reenable` | Re-enable after auth failure |

## Architecture

### Models

| Model | Key fields | Notes |
|---|---|---|
| `MailAccount` | `provider`, `sync_status`, `sync_status_since`, `last_synced_at`, `sync_error`, `is_active` | `provider`: `gmail` / `outlook` / `imap`. Passwords/tokens encrypted at rest via `encrypted` cast. `usesOAuth()` returns true for Gmail/Outlook. |
| `Email` | `mail_account_id`, `thread_id`, `folder`, `uid`, `is_read`, `is_archived`, `is_deleted` | Unique on `(mail_account_id, folder, uid)`. Bodies are null until first open (`fetchBody()`). `threadMessages()` returns all messages in the conversation. `suggestedFolder()` guesses a move destination based on sender history. |
| `MailFolder` | `remote_path`, `local_name`, `last_uid`, `uid_validity` | One row per folder per account. `last_uid` drives incremental sync — only messages with UIDs above this are fetched. `uid_validity` detects folder recreations (triggers full resync). |
| `Contact` | `user_id`, `email`, `name` | Auto-populated from every From/To/CC seen during sync. Powers compose autocomplete. |
| `Draft` | `mail_account_id`, `data` (JSON) | Autosaved from the compose view. |
| `EmailAttachment` | `email_id`, `storage_path` | Written on first body fetch if the message has attachments. |

### Services

**`ImapSyncService`** (`app/Services/Mail/ImapSyncService.php`)

The core sync engine. Called by `SyncMailAccountJob`.

- `sync(MailAccount)` — main entry point. Connects via IMAP, discovers folders,
  runs `syncFolder()` for each.
- `syncFolder()` — incremental by default (fetches only UIDs > `last_uid`).
  Falls back to full fetch when `last_uid = 0` (first sync) or `uid_validity`
  changes (folder was recreated). For each new message: inserts into `emails`,
  broadcasts `NewEmailArrived` event, fires macOS notification via `osascript`.
  For existing messages: reconciles the `is_read` flag against the server's
  `\Seen` flag (catches reads on other devices).
- `fetchBody(Email)` — fetches HTML/text body and attachments on demand.
  Called the first time a message is opened.
- `applyAction(Email, string)` — mirrors a local action back to IMAP.
  Actions: `mark_read`, `mark_unread`, `delete`, `move:<localName>`.

Folder discovery (`foldersToSync()`):
- `INBOX` always synced.
- Sent/Drafts/Trash matched by substring against remote folder name (multilingual).
- Gmail aggregate views (`[Gmail]/All Mail`, `Important`, `Starred`, etc.)
  excluded by name and by structural heuristic (any unmatched folder that
  accounts for >50% of all messages and has >500 messages is treated as a
  duplicate view).

**`MailSenderService`** (`app/Services/Mail/MailSenderService.php`)

Sends mail using the right transport per account type:
- Gmail → Gmail REST API (`/gmail/v1/users/me/messages/send`). Server saves to
  Sent automatically.
- Outlook → Microsoft Graph API (`/me/sendMail` with `saveToSentItems: true`).
  Server saves to Sent automatically.
- Custom IMAP → Symfony Mailer over SMTP, then IMAP-APPENDs the raw message
  to the server's Sent folder (best-effort, silently swallowed on failure).

**`OAuthTokenRefresher`** (`app/Services/Mail/OAuthTokenRefresher.php`)

Exchanges the stored refresh token for a fresh access token when the current
one is expired. Called by `ImapSyncService` and `MailSenderService` before
any API/IMAP operation on OAuth accounts.

### Jobs

**`SyncMailAccountJob`**
- `$timeout = 1800` — 30 min per attempt. Large enough for the initial bulk
  fetch of a large Gmail mailbox; incremental runs complete in seconds.
- `$tries = 3`, `backoff = [60, 300]` — retries after 1 min then 5 min.
- `failed()` — on auth errors (`AUTHENTICATE`, `application-specific password`,
  etc.) sets `is_active = false` to stop the scheduler dispatching it again.
  Non-auth failures (timeout, network) leave `is_active = true` so the account
  self-recovers.

**`ApplyEmailFlagJob`**
- `$timeout = 60`, `$tries = 3`.
- Calls `ImapSyncService::applyAction()` to mirror a local flag change back
  to the mail server. Dispatched optimistically — UI state is already updated
  before this runs.

### Commands

**`mail:sync [--account=ID]`** (`SyncMailboxesCommand`)

Dispatches `SyncMailAccountJob` for every active (`is_active = true`) account.
Skips inactive ones (deactivated by auth failure — needs credentials fixed).
Accounts in `error` status due to transient failures still get dispatched and
self-recover. Run by the scheduler every 5 minutes.

**`mail:idle {account}`** (`IdleMailboxCommand`)

Opens a persistent IMAP IDLE connection on the account's INBOX. When the server
pushes a notification (new message, flag change, expunge), dispatches
`SyncMailAccountJob`. launchd restarts this command if the connection drops
(servers terminate idle connections after ~30 min). Only active accounts have
a launchd agent; see TODOs in README.md for adding new ones.

### Events

**`NewEmailArrived`** (`app/Events/NewEmailArrived.php`)

Broadcast on `ShouldBroadcast` to the private channel `user.{userId}` via
Reverb. Carries: `from_address`, `from_name`, `subject`, `folder`, `sent_at`.
Fired only for new unread INBOX messages during an incremental sync
(`last_uid > 0` — not on first bulk fetch, which would flood the UI).

### Real-time (Reverb + Echo)

Reverb WebSocket server runs on port 8080. Configured in `.env` under
`REVERB_*` / `VITE_REVERB_*`. Channel auth registered in `routes/channels.php`:
`user.{id}` is a private channel gated to the authenticated user.

Browser-side (`resources/js/app.js`): Echo listens on `.new-email` and:
1. Increments the sidebar unread badge immediately.
2. Shows a bottom-right toast with sender and subject (auto-dismisses after 8s).

The sidebar badge also polls `/api/unread-count` every 30s (active tab) or
5 min (background tab) as a fallback.

### Search

`InboxController::searchEmailIds()` uses SQLite FTS5 (`emails_fts` virtual
table) maintained by `AFTER INSERT/UPDATE/DELETE` triggers on the `emails`
table. Indexes `subject`, `from_address`, `body_text`. Terms are quoted and
AND-joined with prefix matching (`"term"*`). Falls back to `LIKE` search if
the FTS table is missing or the query fails.

### Thread grouping

The inbox collapses rows to the latest message per `thread_id` using:

```sql
SELECT MAX(id) FROM emails GROUP BY thread_id
```

`thread_id` is derived from `References` headers during sync: the oldest
message-id in the references chain becomes the thread ID for all replies,
so Gmail-style threads group correctly even across multiple sync runs.
Standalone messages (no References/In-Reply-To) get a unique
`standalone:{account_id}:{folder}:{uid}` thread ID.

### Sync status lifecycle

```
idle → syncing → idle       (happy path)
idle → syncing → error      (transient failure — retries, self-recovers)
idle → syncing → error      (auth failure — is_active = false, needs re-enable)
```

`sync_status_since` is stamped on every transition. Visible on the accounts
page as "since X ago".

## Key gotchas

1. **Auth failures deactivate the account** — `SyncMailAccountJob::failed()`
   checks the exception message for auth-related strings and sets
   `is_active = false`. The scheduler then skips the account. Use the
   "Re-enable" button on `/accounts` after fixing credentials.

2. **Incremental sync depends on `last_uid`** — if `last_uid` is manually
   reset to 0 (e.g. via Tinker), the next sync fetches all messages from
   scratch. This is intentional for force-resync but will be slow on large
   mailboxes.

3. **`NewEmailArrived` is only broadcast on incremental runs** — the guard
   `$folderRecord->last_uid > 0` prevents flooding the UI on first sync.

4. **IMAP IDLE restarts are intentional** — servers drop IDLE connections
   after ~30 minutes. launchd's `KeepAlive: true` restarts the process
   immediately, re-entering IDLE. This is the correct pattern.

5. **Sent folder write-back is best-effort** — `appendToSentFolder()` in
   `MailSenderService` swallows exceptions. A successful SMTP send should
   never fail because of a post-send IMAP operation. The next incremental
   sync will eventually see the Sent message via IMAP anyway.

6. **Queue worker recycles hourly** — `--max-time=3600` causes the worker to
   exit cleanly after 1 hour; launchd restarts it. This picks up code changes
   after deploys without needing a manual restart.

## Testing

```bash
php artisan test                        # all 31 tests
php artisan test tests/Feature/Mail     # mail-specific feature tests
php artisan test tests/Unit             # unit tests (FTS, folder mapping, etc.)
```

Tests use `RefreshDatabase` with the real SQLite file. No database mocking.
Breeze boilerplate tests (`Auth/`, `ProfileTest`) were removed — this app
doesn't have the routes they referenced.
