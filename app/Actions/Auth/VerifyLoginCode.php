<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class VerifyLoginCode
{
    public function handle(User $user, string $code): bool
    {
        if (! $user->login_code_hash || ! $user->login_code_expires_at) {
            return false;
        }

        if ($user->login_code_expires_at->isPast()) {
            return false;
        }

        if (! Hash::check($code, $user->login_code_hash)) {
            return false;
        }

        $user->forceFill([
            'login_code_hash' => null,
            'login_code_expires_at' => null,
        ])->save();

        return true;
    }
}
