<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

class VerifyLoginCode
{
    public const int MAX_ATTEMPTS = 5;

    public function handle(User $user, string $code): bool
    {
        if (! $this->claimAttempt($user)) {
            return false;
        }

        $user->refresh();

        if (! $user->login_code_hash || ! Hash::check($code, $user->login_code_hash)) {
            if ($user->login_code_attempts >= self::MAX_ATTEMPTS) {
                $this->wipeCode($user);
            }

            return false;
        }

        $this->wipeCode($user);

        return true;
    }

    /**
     * The attempt is counted before the hash is checked, in one conditional
     * UPDATE, so parallel guesses cannot all slip in under the cap (ZERO-135).
     */
    private function claimAttempt(User $user): bool
    {
        return User::query()
            ->whereKey($user->getKey())
            ->whereNotNull('login_code_hash')
            ->where('login_code_expires_at', '>', CarbonImmutable::now())
            ->where('login_code_attempts', '<', self::MAX_ATTEMPTS)
            ->increment('login_code_attempts') === 1;
    }

    private function wipeCode(User $user): void
    {
        $user->forceFill([
            'login_code_hash' => null,
            'login_code_expires_at' => null,
            'login_code_attempts' => 0,
        ])->save();
    }
}
