<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Models\OutboundToken;
use Illuminate\Console\Command;

class CreateOutboundTokenCommand extends Command
{
    protected $signature = 'outbound:token-create {name : Who the token is for, e.g. filebox} {--account= : ID of the mail account it sends from}';

    protected $description = 'Create an API token that sends mail from one account (printed once)';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $account = MailAccount::find((int) $this->option('account'));

        if ($account === null || ! $account->is_active) {
            $this->components->error('Pass --account with the ID of an existing, active mail account.');

            return self::FAILURE;
        }

        if (OutboundToken::where('name', $name)->exists()) {
            $this->components->error("A token named \"{$name}\" already exists. Rotate or revoke it, or pick another name.");

            return self::FAILURE;
        }

        [, $plain] = OutboundToken::issue($name, $account);

        $this->components->info("Token for \"{$name}\", sending from {$account->email_address}. It is shown once and cannot be recovered:");
        $this->line($plain);

        return self::SUCCESS;
    }
}
