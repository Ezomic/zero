<x-mail::message>
# Your login code

## {{ $code }}

This code expires in 10 minutes. If you didn't request this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
