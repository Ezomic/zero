<?php

namespace App\Http\Controllers;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class Controller
{
    /**
     * Validation rule for a mail_account_id supplied by the caller.
     *
     * `exists:mail_accounts,id` only proves the row is real, not that it is
     * theirs, so it accepts any account in the system (ZERO-104).
     */
    protected function ownedAccountRule(): Exists
    {
        return Rule::exists('mail_accounts', 'id')->where('user_id', auth()->id());
    }
}
