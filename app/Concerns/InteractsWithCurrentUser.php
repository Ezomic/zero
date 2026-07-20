<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;

trait InteractsWithCurrentUser
{
    /**
     * The authenticated user. Everything using this sits behind the auth
     * middleware, so the null case is unreachable in practice - this makes that
     * guarantee explicit instead of leaving every call site to assume it.
     *
     * @throws AuthenticationException
     */
    protected function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
