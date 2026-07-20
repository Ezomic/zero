<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    use InteractsWithCurrentUser;

    public function show(Request $request): View
    {
        return view('security.show', [
            'passkeys' => $this->currentUser()->passkeys()->orderByDesc('created_at')->get(),
        ]);
    }
}
