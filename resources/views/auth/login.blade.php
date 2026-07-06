<x-guest-layout :title="'Log in'">
    <h1>Welcome back</h1>
    <p class="sub">One inbox. Every account.</p>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div class="field">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <!-- Password -->
        <div class="field">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div class="row-between">
            <label class="checkline">
                <input type="checkbox" name="remember">
                {{ __('Remember me') }}
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" style="color:var(--text-dim); font-weight:600;">
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-primary-button>
            {{ __('Log in') }}
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
</x-guest-layout>
