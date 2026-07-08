<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginCodeController extends Controller
{
    public function send(Request $request, SendLoginCode $sendLoginCode): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $sendLoginCode->handle($user);
        }

        return redirect()->route('login.code.challenge')
            ->withInput($data)
            ->with('status', 'If that email belongs to an account, a login code is on its way.');
    }

    public function show(): View
    {
        return view('auth.login-code-challenge');
    }

    public function verify(Request $request, VerifyLoginCode $verifyLoginCode): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && $verifyLoginCode->handle($user, $data['code'])) {
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended(route('inbox.index', absolute: false));
        }

        return back()->withInput($data)->withErrors(['code' => 'That code is invalid or has expired.']);
    }
}
