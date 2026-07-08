<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Skips typing credentials during local development. The route this serves
 * is only registered when app()->environment('local') — see routes/auth.php.
 */
class DevLoginController extends Controller
{
    public function store(): RedirectResponse
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => now(),
            ]
        );

        Auth::login($user);

        return redirect()->route('inbox.index');
    }
}
