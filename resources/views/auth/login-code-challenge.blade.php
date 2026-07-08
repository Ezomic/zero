<x-guest-layout :title="'Enter your code'">
    <h1>Enter your login code</h1>
    <p class="sub">Sent to your inbox — check your email.</p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login.code.verify') }}">
        @csrf
        <div class="field">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div class="field">
            <x-input-label for="code" :value="__('Login code')" />
            <x-text-input id="code" type="text" name="code" :value="old('code')" required
                autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" />
            <x-input-error :messages="$errors->get('code')" />
        </div>

        <x-primary-button>{{ __('Verify') }}</x-primary-button>
    </form>

    <div class="login-divider">
        <a href="{{ route('login') }}">Back to login</a>
    </div>
</x-guest-layout>
