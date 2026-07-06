<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') &middot; @endif Zero</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#7C6FF0">
    <script>
        (function () {
            const stored = localStorage.getItem('theme');
            document.documentElement.setAttribute('data-theme', stored === 'light' ? 'light' : 'dark');
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @include('components.icon-sprite')

    <div class="app-shell">
        <aside class="navrail">
            <x-nav-rail/>
        </aside>

        <main class="main">
            <div style="padding: 14px 22px 0;">
                @if (session('status'))
                    <div class="flash success"><svg class="ic-sm"><use href="#i-check"/></svg>{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="flash error"><svg class="ic-sm"><use href="#i-alert"/></svg>{{ session('error') }}</div>
                @endif
            </div>

            @yield('content')
        </main>
    </div>

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js');
        }
    </script>

    @auth
        <script>
            (function () {
                function setTheme(theme) {
                    document.documentElement.setAttribute('data-theme', theme);
                    localStorage.setItem('theme', theme);
                    const icon = document.getElementById('footThemeIcon');
                    if (icon) icon.querySelector('use').setAttribute('href', theme === 'dark' ? '#i-moon' : '#i-sun');
                }
                document.getElementById('footThemeToggle')?.addEventListener('click', () => {
                    const current = document.documentElement.getAttribute('data-theme');
                    setTheme(current === 'dark' ? 'light' : 'dark');
                });
                setTheme(document.documentElement.getAttribute('data-theme'));
            })();
        </script>
    @endauth

    @auth
        <script>
            (function () {
                const INTERVAL_ACTIVE = 30_000;
                const INTERVAL_BACKGROUND = 5 * 60_000;
                let timer = null;

                window.updateUnreadBadge = function updateBadge(count) {
                    const badge = document.getElementById('unread-badge');
                    if (!badge) return;
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.classList.remove('hidden');
                        document.title = document.title.replace(/^\(\d+\+?\) /, '');
                        document.title = '(' + (count > 99 ? '99+' : count) + ') ' + document.title;
                    } else {
                        badge.classList.add('hidden');
                        document.title = document.title.replace(/^\(\d+\+?\) /, '');
                    }
                };
                const updateBadge = window.updateUnreadBadge;

                function poll() {
                    fetch('{{ route('inbox.unreadCount') }}', { headers: { 'Accept': 'application/json' } })
                        .then((r) => r.json())
                        .then((data) => updateBadge(data.unread ?? 0))
                        .catch(() => {});
                }

                function schedule() {
                    clearInterval(timer);
                    const interval = document.hidden ? INTERVAL_BACKGROUND : INTERVAL_ACTIVE;
                    timer = setInterval(poll, interval);
                }

                poll();
                schedule();

                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) poll();
                    schedule();
                });
            })();
        </script>

        {{-- Live inbox: poll for new emails and inject them without a page reload --}}
        <script>
            (function () {
                const list = document.getElementById('email-list');
                if (!list) return;

                let newestId = parseInt(list.dataset.newestId ?? '0', 10);

                const params = new URLSearchParams(location.search);
                const apiBase = '{{ route('inbox.newEmails') }}';

                function buildUrl() {
                    const p = new URLSearchParams();
                    p.set('since', newestId);
                    if (params.has('account')) p.set('account', params.get('account'));
                    if (params.has('folder')) p.set('folder', params.get('folder'));
                    if (params.has('archived')) p.set('archived', params.get('archived'));
                    return apiBase + '?' + p.toString();
                }

                function showBanner(count) {
                    let banner = document.getElementById('new-mail-banner');
                    if (!banner) {
                        banner = document.createElement('div');
                        banner.id = 'new-mail-banner';
                        banner.className = 'flash';
                        banner.style.background = 'var(--accent-soft)';
                        banner.style.color = 'var(--accent-text)';
                        banner.style.cursor = 'pointer';
                        banner.onclick = () => banner.remove();
                        list.parentElement.insertBefore(banner, list);
                    }
                    banner.textContent = count + ' new message' + (count > 1 ? 's' : '') + ' — click to dismiss';
                }

                function pollNew() {
                    fetch(buildUrl(), { headers: { 'Accept': 'application/json' } })
                        .then((r) => r.json())
                        .then(({ html, newest_id }) => {
                            if (!html || html.length === 0) return;

                            const empty = document.getElementById('empty-state');
                            if (empty) empty.remove();

                            const frag = document.createDocumentFragment();
                            html.forEach((rowHtml) => {
                                const div = document.createElement('div');
                                div.innerHTML = rowHtml;
                                while (div.firstChild) frag.appendChild(div.firstChild);
                            });
                            list.insertBefore(frag, list.firstChild);

                            newestId = newest_id;
                            showBanner(html.length);
                        })
                        .catch(() => {});
                }

                const INTERVAL_ACTIVE = 30_000;
                const INTERVAL_BACKGROUND = 5 * 60_000;
                let newMailTimer = null;

                function scheduleNew() {
                    clearInterval(newMailTimer);
                    newMailTimer = setInterval(pollNew, document.hidden ? INTERVAL_BACKGROUND : INTERVAL_ACTIVE);
                }

                scheduleNew();
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) pollNew();
                    scheduleNew();
                });
            })();
        </script>
    @endauth

    @auth
        <script>
            (function () {
                const routes = {
                    inbox: '{{ route('inbox.index') }}',
                    compose: '{{ route('compose.create') }}',
                    sent: '{{ route('inbox.index', ['folder' => 'SENT']) }}',
                    drafts: '{{ route('drafts.index') }}',
                    triage: '{{ route('triage.index') }}',
                    accounts: '{{ route('accounts.index') }}',
                };

                function inInput() {
                    const tag = document.activeElement?.tagName;
                    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable;
                }

                document.addEventListener('keydown', function (e) {
                    if (e.metaKey || e.ctrlKey || e.altKey || inInput()) return;

                    // ? → show shortcut help overlay
                    if (e.key === '?') {
                        const overlay = document.getElementById('kb-help');
                        if (overlay) overlay.classList.toggle('hidden');
                        return;
                    }

                    // g then second key — goto shortcuts
                    if (e.key === 'g') {
                        const listener = function (e2) {
                            document.removeEventListener('keydown', listener);
                            if (inInput()) return;
                            const dest = { i: routes.inbox, s: routes.sent, d: routes.drafts, t: routes.triage, c: routes.compose, a: routes.accounts }[e2.key];
                            if (dest) location.href = dest;
                        };
                        document.addEventListener('keydown', listener, { once: true });
                        return;
                    }

                    const link = document.querySelector('.email-row.focused a[data-href]');
                    const focused = document.querySelector('.email-row.focused');

                    const rows = Array.from(document.querySelectorAll('.email-row'));
                    const idx = rows.indexOf(focused);

                    switch (e.key) {
                        case 'j': {
                            const next = rows[Math.min(idx + 1, rows.length - 1)];
                            rows.forEach((r) => r.classList.remove('focused'));
                            next?.classList.add('focused');
                            next?.scrollIntoView({ block: 'nearest' });
                            break;
                        }
                        case 'k': {
                            const prev = rows[Math.max(idx - 1, 0)];
                            rows.forEach((r) => r.classList.remove('focused'));
                            prev?.classList.add('focused');
                            prev?.scrollIntoView({ block: 'nearest' });
                            break;
                        }
                        case 'Enter':
                        case 'o': {
                            if (focused && window.__openInboxThread) {
                                window.__openInboxThread(focused);
                            } else {
                                const href = focused?.querySelector('a')?.href;
                                if (href) location.href = href;
                            }
                            break;
                        }
                        case 'c':
                            location.href = routes.compose;
                            break;
                        case '/':
                            e.preventDefault();
                            document.querySelector('input[name="q"]')?.focus();
                            break;
                        case 'Escape': {
                            document.getElementById('kb-help')?.classList.add('hidden');
                            document.querySelector('input[name="q"]')?.blur();
                            break;
                        }
                        case 'e': {
                            const form = focused?.querySelector('form[data-action="archive"]');
                            if (form) form.requestSubmit();
                            break;
                        }
                        case 'u': {
                            const form = focused?.querySelector('form[data-action="unread"]');
                            if (form) form.requestSubmit();
                            break;
                        }
                        case '#': {
                            if (confirm('Delete this conversation?')) {
                                focused?.querySelector('form[data-action="delete"]')?.requestSubmit();
                            }
                            break;
                        }
                        case 'r': {
                            const href = focused?.querySelector('a[data-action="reply"]')?.href;
                            if (href) location.href = href;
                            break;
                        }
                    }
                });
            })();
        </script>

        {{-- Keyboard shortcut help overlay --}}
        <div id="kb-help" class="hidden" style="position:fixed; inset:0; z-index:50; background:rgba(0,0,0,.5); align-items:center; justify-content:center;" onclick="this.classList.add('hidden')">
            <div class="card" style="width:320px; padding:22px; box-shadow:var(--shadow);" onclick="event.stopPropagation()">
                <h2 style="font-weight:700; font-size:15px; margin:0 0 14px;">Keyboard shortcuts</h2>
                <table style="width:100%; font-size:12.5px; border-collapse:collapse;">
                    <tbody>
                        @foreach ([
                            ['j / k', 'Move between conversations'],
                            ['Enter / o', 'Open focused conversation'],
                            ['e', 'Archive focused conversation'],
                            ['u', 'Mark focused conversation unread'],
                            ['#', 'Delete focused conversation'],
                            ['r', 'Reply to focused conversation'],
                            ['c', 'Compose new message'],
                            ['/', 'Focus search'],
                            ['gi', 'Go to Inbox'],
                            ['gs', 'Go to Sent'],
                            ['gd', 'Go to Drafts'],
                            ['gt', 'Go to Process Inbox (triage)'],
                            ['gc', 'Go to Compose'],
                            ['ga', 'Go to Accounts'],
                            ['?', 'Toggle this help'],
                        ] as [$key, $desc])
                            <tr style="border-top:1px solid var(--border-soft);">
                                <td style="padding:6px 10px 6px 0; width:74px;"><kbd style="border:1px solid var(--border); background:var(--bg-2); border-radius:4px; padding:1px 6px; font-size:10.5px; font-family:monospace;">{{ $key }}</kbd></td>
                                <td style="padding:6px 0; color:var(--text-dim);">{{ $desc }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button class="btn ghost sm" style="margin-top:14px;" onclick="document.getElementById('kb-help').classList.add('hidden')">Close</button>
            </div>
        </div>
    @endauth

    @yield('scripts')

    @auth
        <div id="new-email-toast" class="hidden card" style="position:fixed; bottom:16px; right:16px; z-index:50; max-width:22rem; box-shadow:var(--shadow); padding:14px; align-items:flex-start; gap:10px; cursor:pointer;" onclick="window.location.reload()">
            <div style="flex-shrink:0; width:8px; height:8px; border-radius:50%; background:var(--accent); margin-top:5px;"></div>
            <div style="min-width:0;">
                <p style="font-size:13px; font-weight:700; margin:0;" id="toast-from">New message</p>
                <p style="font-size:12.5px; color:var(--text-dim); margin:2px 0 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="toast-subject"></p>
                <p style="font-size:11px; color:var(--text-faint); margin:2px 0 0;">Click to refresh</p>
            </div>
            <button style="flex-shrink:0; color:var(--text-faint); background:none; border:none; margin-left:6px;" onclick="event.stopPropagation(); document.getElementById('new-email-toast').classList.add('hidden')">✕</button>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                window.Echo.private('user.{{ auth()->id() }}')
                    .listen('.new-email', (e) => {
                        // Update badge immediately — don't wait for the next poll.
                        const badge = document.getElementById('unread-badge');
                        const current = parseInt(badge?.textContent ?? '0', 10) || 0;
                        if (window.updateUnreadBadge) window.updateUnreadBadge(current + 1);

                        const toast = document.getElementById('new-email-toast');
                        document.getElementById('toast-from').textContent = e.from_name || e.from_address;
                        document.getElementById('toast-subject').textContent = e.subject;
                        toast.classList.remove('hidden');
                        setTimeout(() => toast.classList.add('hidden'), 8000);
                    });
            });
        </script>
    @endauth
</body>
</html>
