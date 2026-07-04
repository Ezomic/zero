<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Mail')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>.email-row.focused { box-shadow: inset 3px 0 0 #2563EB; background-color: #EFF6FF; }</style>
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="min-h-screen flex">
        <aside class="w-60 bg-white border-r border-gray-200 p-4 flex flex-col gap-1">
            <a href="{{ route('inbox.index') }}" class="text-lg font-semibold mb-4">📬 Mail</a>

            <a href="{{ route('inbox.index') }}" class="px-3 py-2 rounded hover:bg-gray-100 flex items-center justify-between">
                <span>Inbox</span>
                <span id="unread-badge" class="text-xs bg-blue-600 text-white rounded-full px-2 py-0.5 hidden"></span>
            </a>
            <a href="{{ route('inbox.index', ['folder' => 'SENT']) }}" class="px-3 py-2 rounded hover:bg-gray-100">Sent</a>
            <a href="{{ route('drafts.index') }}" class="px-3 py-2 rounded hover:bg-gray-100">Drafts</a>
            <a href="{{ route('inbox.index', ['folder' => 'TRASH']) }}" class="px-3 py-2 rounded hover:bg-gray-100">Trash</a>
            <a href="{{ route('inbox.index', ['archived' => 1]) }}" class="px-3 py-2 rounded hover:bg-gray-100">Archived</a>
            <a href="{{ route('triage.index') }}" class="px-3 py-2 rounded hover:bg-gray-100">🧹 Process Inbox</a>

            <a href="{{ route('compose.create') }}" class="mt-2 px-3 py-2 rounded bg-blue-600 text-white text-center">Compose</a>
            <a href="{{ route('accounts.index') }}" class="px-3 py-2 rounded hover:bg-gray-100">Accounts</a>

            @auth
                @php $sidebarAccounts = auth()->user()->mailAccounts()->get(); @endphp
                @if ($sidebarAccounts->isNotEmpty())
                    <div class="mt-4 pt-3 border-t text-xs uppercase tracking-wide text-gray-400 px-3">Accounts</div>
                    <div class="flex flex-col gap-1">
                        @foreach ($sidebarAccounts as $sidebarAccount)
                            @php $sidebarUnread = $sidebarAccount->unreadCount(); @endphp
                            <a href="{{ route('inbox.index', ['account' => $sidebarAccount->id]) }}" class="px-3 py-1.5 rounded hover:bg-gray-100 flex items-center gap-2 text-sm">
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: {{ $sidebarAccount->color }}"></span>
                                <span class="truncate flex-1">{{ $sidebarAccount->display_name ?: $sidebarAccount->email_address }}</span>
                                @if ($sidebarUnread > 0)
                                    <span class="text-xs text-gray-400">{{ $sidebarUnread }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endauth

            <div class="mt-auto flex flex-col gap-1">
                <button onclick="document.getElementById('kb-help').classList.toggle('hidden')" class="px-3 py-2 rounded hover:bg-gray-100 w-full text-left text-sm text-gray-400">⌨ Shortcuts</button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="px-3 py-2 rounded hover:bg-gray-100 w-full text-left text-sm text-gray-500">Log out</button>
                </form>
            </div>
        </aside>

        <main class="flex-1 p-6">
            @if (session('status'))
                <div class="mb-4 rounded bg-green-100 text-green-800 px-4 py-2 text-sm">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded bg-red-100 text-red-800 px-4 py-2 text-sm">{{ session('error') }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    @auth
        <script>
            (function () {
                const INTERVAL_ACTIVE = 30_000;
                const INTERVAL_BACKGROUND = 5 * 60_000;
                let timer = null;

                function updateBadge(count) {
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
                }

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
                            const href = focused?.querySelector('a')?.href;
                            if (href) location.href = href;
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
        <div id="kb-help" class="hidden fixed inset-0 z-50 bg-black/40 flex items-center justify-center" onclick="this.classList.add('hidden')">
            <div class="bg-white rounded-lg shadow-xl p-6 w-80" onclick="event.stopPropagation()">
                <h2 class="font-semibold mb-4">Keyboard shortcuts</h2>
                <table class="w-full text-sm">
                    <tbody class="divide-y">
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
                            <tr class="py-1">
                                <td class="py-1 pr-4 font-mono text-xs bg-gray-100 rounded px-1 text-center w-20">{{ $key }}</td>
                                <td class="py-1 pl-3 text-gray-600">{{ $desc }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button class="mt-4 text-xs text-gray-400 hover:text-gray-600" onclick="document.getElementById('kb-help').classList.add('hidden')">Close</button>
            </div>
        </div>
    @endauth

    @yield('scripts')
</body>
</html>
