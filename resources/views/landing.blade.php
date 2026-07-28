<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title>Zero &mdash; one inbox for every account</title>
    <meta name="theme-color" content="#EF6A5E">
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

    <div class="lp">
        <header class="lp-top">
            <a href="{{ route('inbox.index') }}" class="brand" style="padding:0;">
                <div class="brand-mark"><svg class="ic-sm" style="color:#fff"><use href="#i-inbox"/></svg></div>
                <div class="brand-name">Zero</div>
            </a>
            <div class="lp-top-actions">
                <button type="button" class="icon-btn" id="lpThemeToggle" aria-label="Toggle theme">
                    <svg class="ic" id="lpThemeIcon"><use href="#i-moon"/></svg>
                </button>
                <a href="{{ route('login') }}" class="btn primary sm">Sign in</a>
            </div>
        </header>

        <main class="lp-main">
            <section class="lp-hero">
                <div class="lp-hero-copy">
                    <span class="lp-eyebrow"><span class="m"></span>Multi-account mail client</span>
                    <h1>One inbox for every account.<br><span class="soft">On every device. Email you own.</span></h1>
                    <p class="lp-lede">Zero pulls Gmail, Outlook and any IMAP mailbox &mdash; folders and all &mdash; into a single fast inbox. Self-hosted, synced across your devices, your data stays yours.</p>
                    <div class="lp-cta">
                        <a href="{{ route('login') }}" class="btn primary lg">Get started</a>
                        <a href="{{ route('login') }}" class="btn lg">Sign in</a>
                    </div>
                    <div class="lp-trust">
                        <span><b>Gmail</b> &middot; <b>Outlook</b> &middot; <b>IMAP / SMTP</b></span>
                        <span><b>Self-hosted</b></span>
                        <span>Your database, exportable any time</span>
                    </div>
                </div>

                <div class="lp-hero-art" aria-hidden="true">
                    <div class="lp-art-bar"><i></i><i></i><i></i><span class="t">zero &mdash; unified inbox</span></div>
                    <div class="lp-prow">
                        <div class="avatar" style="background:#e0567a">SK</div>
                        <div class="lp-pmain"><div class="lp-pt"><span class="pn">Skillshare</span><span class="pm">2:06 PM</span></div><div class="ps">Here&rsquo;s what to watch next &mdash; 3 classes for you</div><div class="lp-pa"><span class="acct-dot" style="background:#e0567a"></span>you@gmail.com</div></div>
                    </div>
                    <div class="lp-prow">
                        <div class="avatar" style="background:#3b7dd8">GH</div>
                        <div class="lp-pmain"><div class="lp-pt"><span class="pn">GitHub</span><span class="pm">11:24 AM</span></div><div class="ps">CI passed on your latest branch</div><div class="lp-pa"><span class="acct-dot" style="background:#2f9e6f"></span>you@work.com</div></div>
                    </div>
                    <div class="lp-prow">
                        <div class="avatar" style="background:#7b61ff">IN</div>
                        <div class="lp-pmain"><div class="lp-pt"><span class="pn">LinkedIn</span><span class="pm">Yesterday</span></div><div class="ps">You appeared in 9 searches this week</div><div class="lp-pa"><span class="acct-dot" style="background:#3b7dd8"></span>you@outlook.com</div></div>
                    </div>
                </div>
            </section>

            <section class="lp-feats">
                <div class="lp-feat">
                    <div class="lp-fi"><svg class="ic"><use href="#i-inbox"/></svg></div>
                    <h3>Every account, one inbox</h3>
                    <p>Gmail, Outlook/Hotmail and custom IMAP, merged &mdash; with all your folders and labels kept intact and browsable.</p>
                    <div class="lp-chips"><span class="lp-chip">Gmail</span><span class="lp-chip">Outlook</span><span class="lp-chip">IMAP / SMTP</span></div>
                </div>
                <div class="lp-feat">
                    <div class="lp-fi"><svg class="ic"><use href="#i-sparkle"/></svg></div>
                    <h3>Fast on every device</h3>
                    <p>A command palette and single-key triage on desktop; the same inbox, synced and legible, on your phone.</p>
                    <div class="lp-chips"><span class="lp-chip">Keyboard-first</span><span class="lp-chip">Triage</span><span class="lp-chip">Responsive</span></div>
                </div>
                <div class="lp-feat">
                    <div class="lp-fi"><svg class="ic"><use href="#i-check"/></svg></div>
                    <h3>Email you actually own</h3>
                    <p>Self-hosted on your own box. Your mail lives in your database, exportable any time &mdash; not a vendor&rsquo;s.</p>
                    <div class="lp-chips"><span class="lp-chip">Self-hosted</span><span class="lp-chip">Your DB</span><span class="lp-chip">Real-time sync</span></div>
                </div>
            </section>

            <section class="lp-final">
                <h2>Bring your mailboxes home.</h2>
                <p>One fast inbox for Gmail, Outlook and IMAP &mdash; on every device, entirely yours.</p>
                <a href="{{ route('login') }}" class="btn primary lg">Get started</a>
            </section>
        </main>

        <footer class="lp-foot">
            <span>Zero &mdash; a mail client by Thijssen Software</span>
            <a href="{{ route('login') }}">Sign in</a>
        </footer>
    </div>

    <script>
        (function () {
            function setTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
                const icon = document.getElementById('lpThemeIcon');
                if (icon) icon.querySelector('use').setAttribute('href', theme === 'dark' ? '#i-moon' : '#i-sun');
            }
            document.getElementById('lpThemeToggle')?.addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme');
                setTheme(current === 'dark' ? 'light' : 'dark');
            });
            setTheme(document.documentElement.getAttribute('data-theme'));
        })();
    </script>
</body>
</html>
