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

    /**
     * validate() is typed as returning array<array-key, mixed>, which is not
     * an update() payload at PHPStan level 10. Rekeying it once here is what
     * every caller needs, and keeps the branches from each rebuilding it (one
     * of them forgot to, which is how display_name went missing: ZERO-88).
     *
     * @param  mixed  $validated  the return of Request::validate()
     * @return array<string, mixed>
     */
    protected function stringKeyed($validated): array
    {
        $data = [];

        foreach (is_array($validated) ? $validated : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }
}
