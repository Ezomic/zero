@extends('layouts.app')

@section('title', 'Confirm it\'s you')

@section('content')
    <div class="page-pad" style="max-width:420px;">
        <div class="page-head"><h1>Confirm it's you</h1></div>

        <p style="color:var(--text-dim); font-size:13px; margin:0 0 20px;">
            This is a sensitive security action. We'll email you a code to confirm before continuing.
        </p>

        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form method="POST" action="{{ route('password.confirm.send') }}">
            @csrf
            <x-primary-button>{{ __('Send code') }}</x-primary-button>
        </form>

        @if (session('status'))
            <form method="POST" action="{{ route('password.confirm.verify') }}" style="margin-top:18px;">
                @csrf
                <div class="field">
                    <x-input-label for="code" :value="__('Code')" />
                    <x-text-input id="code" type="text" name="code" required autofocus
                        autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" />
                    <x-input-error :messages="$errors->get('code')" />
                </div>

                <x-primary-button>{{ __('Confirm') }}</x-primary-button>
            </form>
        @endif
    </div>
@endsection
