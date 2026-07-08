@extends('layouts.app')

@section('title', 'Security')

@section('content')
    <div class="page-pad" style="width:100%;">
        <div class="page-head"><h1>Security</h1></div>

        <p style="color:var(--text-dim); font-size:13px; margin:0 0 20px;">
            Sign in with a passkey instead of a password — fingerprint, face, or device PIN.
        </p>

        <div id="passkey-error" class="flash error" style="display:none;"></div>

        <div class="acct-grid">
            @forelse ($passkeys as $passkey)
                <div class="acct-card" data-passkey-row="{{ $passkey->id }}">
                    <div class="acct-card-top">
                        <div style="min-width:0;">
                            <div class="addr">{{ $passkey->name }}</div>
                            <div class="provider">
                                {{ $passkey->authenticator ?? 'Passkey' }} &middot;
                                {{ $passkey->last_used_at ? 'last used '.$passkey->last_used_at->diffForHumans() : 'never used' }}
                            </div>
                        </div>
                    </div>
                    <div class="acct-card-foot">
                        <button type="button" class="btn sm ghost danger" data-delete-passkey="{{ $passkey->id }}" style="margin-left:auto;">Remove</button>
                    </div>
                </div>
            @empty
                <p style="color:var(--text-faint);">No passkeys registered yet.</p>
            @endforelse
        </div>

        <button type="button" id="add-passkey-btn" class="btn primary" style="margin-top:18px;">
            <svg class="ic-sm"><use href="#i-plus"/></svg>Add a passkey
        </button>
        <p id="passkey-unsupported" style="display:none; color:var(--text-faint); font-size:12px; margin-top:10px;">
            Your browser doesn't support passkeys.
        </p>
    </div>

    <script>
        (function () {
            const errorEl = document.getElementById('passkey-error');
            const addBtn = document.getElementById('add-passkey-btn');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

            const supported = typeof window.PublicKeyCredential !== 'undefined'
                && typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function';

            if (! supported) {
                addBtn.style.display = 'none';
                document.getElementById('passkey-unsupported').style.display = 'block';
                return;
            }

            function showError(message) {
                errorEl.textContent = message;
                errorEl.style.display = 'flex';
            }

            function redirectIfConfirmationRequired(res) {
                if (res.status === 423) {
                    window.location = '{{ route('password.confirm') }}';

                    return true;
                }

                return false;
            }

            addBtn.addEventListener('click', async () => {
                errorEl.style.display = 'none';

                const name = prompt('Name this passkey (e.g. "MacBook Touch ID")');
                if (! name) return;

                try {
                    const optionsRes = await fetch('{{ route('passkey.registration-options') }}', {
                        headers: { Accept: 'application/json' },
                    });
                    if (redirectIfConfirmationRequired(optionsRes)) return;
                    if (! optionsRes.ok) throw new Error();
                    const { options } = await optionsRes.json();

                    const publicKey = PublicKeyCredential.parseCreationOptionsFromJSON(options);
                    const credential = await navigator.credentials.create({ publicKey });

                    const storeRes = await fetch('{{ route('passkey.store') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ name, credential: credential.toJSON() }),
                    });
                    if (redirectIfConfirmationRequired(storeRes)) return;
                    if (! storeRes.ok) throw new Error();

                    window.location.reload();
                } catch (error) {
                    showError('Could not register the passkey. Please try again.');
                }
            });

            document.querySelectorAll('[data-delete-passkey]').forEach((button) => {
                button.addEventListener('click', async () => {
                    if (! confirm('Remove this passkey?')) return;

                    const id = button.dataset.deletePasskey;

                    try {
                        const res = await fetch(`{{ url('/user/passkeys') }}/${id}`, {
                            method: 'DELETE',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                        });
                        if (redirectIfConfirmationRequired(res)) return;
                        if (! res.ok) throw new Error();

                        document.querySelector(`[data-passkey-row="${id}"]`).remove();
                    } catch (error) {
                        showError('Could not remove the passkey. Please try again.');
                    }
                });
            });
        })();
    </script>
@endsection
