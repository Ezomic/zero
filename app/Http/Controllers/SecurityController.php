<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        return view('security.show', [
            'passkeys' => $request->user()->passkeys()->orderByDesc('created_at')->get(),
        ]);
    }
}
