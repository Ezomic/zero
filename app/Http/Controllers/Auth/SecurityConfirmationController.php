<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityConfirmationController extends Controller
{
    public function show(): View
    {
        return view('auth.confirm-identity');
    }

    public function send(Request $request, SendLoginCode $sendLoginCode): RedirectResponse
    {
        $sendLoginCode->handle($request->user());

        return redirect()->route('password.confirm')
            ->with('status', 'A code is on its way to your inbox.');
    }

    public function verify(Request $request, VerifyLoginCode $verifyLoginCode): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        if ($verifyLoginCode->handle($request->user(), $data['code'])) {
            $request->session()->passwordConfirmed();

            return redirect()->intended(route('security.show', absolute: false));
        }

        return back()->withErrors(['code' => 'That code is invalid or has expired.']);
    }
}
