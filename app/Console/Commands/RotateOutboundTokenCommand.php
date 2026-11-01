<?php

namespace App\Console\Commands;

use App\Models\OutboundToken;
use Illuminate\Console\Command;

class RotateOutboundTokenCommand extends Command
{
    protected $signature = 'outbound:token-rotate {name}';

    protected $description = 'Replace an API token\'s secret; the old one stops working at once (new one printed once)';

    public function handle(): int
    {
        $token = OutboundToken::where('name', (string) $this->argument('name'))->first();

        if ($token === null || $token->isRevoked()) {
            $this->components->error('No active token with that name. A revoked token cannot be rotated; create a new one.');

            return self::FAILURE;
        }

        $this->components->info("New secret for \"{$token->name}\". It is shown once and cannot be recovered:");
        $this->line($token->rotate());

        return self::SUCCESS;
    }
}
