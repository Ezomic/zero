@props(['title' => 'Log in'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title }} &middot; Zero</title>

        <script>
            (function () {
                const stored = localStorage.getItem('theme');
                document.documentElement.setAttribute('data-theme', stored === 'light' ? 'light' : 'dark');
            })();
        </script>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        @include('components.icon-sprite')

        <div class="login-wrap">
            <div class="login-blob b1"></div>
            <div class="login-blob b2"></div>
            <div class="login-card">
                <div class="login-mark"><svg class="ic" style="color:#fff; width:22px; height:22px;"><use href="#i-inbox"/></svg></div>
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
