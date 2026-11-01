<?php

namespace App\Console\Commands;

use App\Models\OutboundToken;
use Illuminate\Console\Command;

class RevokeOutboundTokenCommand extends Command
{
    protected $signature = 'outbound:token-revoke {name}';

    protected $description = 'Revoke an API token so it can no longer send';

    public function handle(): int
    {
        $token = OutboundToken::where('name', (string) $this->argument('name'))->first();

        if ($token === null) {
            $this->components->error('No token with that name.');

            return self::FAILURE;
        }

        $token->revoke();
        $this->components->info("Token \"{$token->name}\" revoked.");

        return self::SUCCESS;
    }
}
