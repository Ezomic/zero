<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class BootstrapPasskeyLogin extends Command
{
    protected $signature = 'passkeys:bootstrap {email : The account email to generate a login code for}';

    protected $description = 'Print a login code to the console instead of emailing it, for use when production mail is down and no passkey is registered yet';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No account found with that email.');

            return self::FAILURE;
        }

        $code = (string) random_int(100000, 999999);

        $user->forceFill([
            'login_code_hash' => Hash::make($code),
            'login_code_expires_at' => CarbonImmutable::now()->addMinutes(10),
        ])->save();

        $this->info("Login code for {$user->email}: {$code}");
        $this->line('Expires in 10 minutes. Enter this email and code directly at /login/code.');

        return self::SUCCESS;
    }
}
