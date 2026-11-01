<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Exactly one recipient, a plain address with no display name, and no line
 * breaks in anything that ends up in a header (ZERO-131). Unknown keys such as
 * `from` or `account` are never read: the sender is the token's account.
 */
class SendOutboundRequest extends FormRequest
{
    public const SUBJECT_MAX = 200;

    public const TEXT_MAX = 5000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'max:254', 'ascii', 'email:rfc', 'regex:/^[^@]+@[^@]+\.[^@]+$/', 'not_regex:/[\s,;<>()"\[\]%]/'],
            'subject' => ['required', 'string', 'max:'.self::SUBJECT_MAX, 'not_regex:/[\x00-\x1F\x7F]/'],
            'text' => ['required', 'string', 'max:'.self::TEXT_MAX],
        ];
    }
}
