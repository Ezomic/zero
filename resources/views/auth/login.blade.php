<x-guest-layout :title="'Log in'">
    <h1>Welcome back</h1>
    <p class="sub">One inbox. Every account.</p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div id="passkey-error" class="field-error" style="display:none; margin-bottom:14px;"></div>

    <button type="button" id="passkey-login-btn" class="btn primary" style="width:100%;">
        {{ __('Sign in with passkey') }}
    </button>
    <p id="passkey-unsupported" style="display:none; text-align:center; font-size:12px; color:var(--text-faint); margin-top:10px;">
        Your browser doesn't support passkeys — use a login code instead.
    </p>

    <div class="login-divider">or</div>

    <form method="POST" action="{{ route('login.code.send') }}">
        @csrf
        <div class="field">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <x-primary-button style="background:transparent; border:1px solid var(--border); color:var(--text-dim);">
            {{ __('Email me a login code') }}
        </x-primary-button>
    </form>

    @if (app()->environment('local') && Route::has('dev-login'))
        <div class="login-divider">DEVELOPMENT</div>
        <form method="POST" action="{{ route('dev-login') }}">
            @csrf
            <button type="submit" class="login-dev" style="width:100%; border:none; background:none;">
                Continue as <strong style="color:var(--text-dim)">test@example.com</strong>
            </button>
        </form>
    @endif

    <script>
        (function () {
            const btn = document.getElementById('passkey-login-btn');
            const errorEl = document.getElementById('passkey-error');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

            const supported = typeof window.PublicKeyCredential !== 'undefined'
                && typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function';

            if (! supported) {
                btn.style.display = 'none';
                document.getElementById('passkey-unsupported').style.display = 'block';
                return;
            }

            btn.addEventListener('click', async () => {
                errorEl.style.display = 'none';

                try {
                    const optionsRes = await fetch('{{ route('passkey.login-options') }}', {
                        headers: { Accept: 'application/json' },
                    });
                    if (! optionsRes.ok) throw new Error();
                    const { options } = await optionsRes.json();

                    const publicKey = PublicKeyCredential.parseRequestOptionsFromJSON(options);
                    const credential = await navigator.credentials.get({ publicKey });

                    const loginRes = await fetch('{{ route('passkey.login') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ credential: credential.toJSON(), remember: true }),
                    });
                    if (! loginRes.ok) throw new Error();

                    const { redirect } = await loginRes.json();
                    window.location = redirect || '{{ route('inbox.index') }}';
                } catch (error) {
                    errorEl.textContent = 'Passkey sign-in failed. Please try again or use a login code.';
                    errorEl.style.display = 'block';
                }
            });
        })();
    </script>
</x-guest-layout>
