<?php

namespace App\Console\Commands;

use App\Actions\Mail\SweepOutgoingMessages;
use Illuminate\Console\Command;

class SweepOutgoingMessagesCommand extends Command
{
    protected $signature = 'outgoing:sweep';

    protected $description = 'Re-queue held messages whose job was lost, fail ones stuck mid-send, and purge expired files';

    public function handle(SweepOutgoingMessages $sweep): int
    {
        $sweep->handle();

        return self::SUCCESS;
    }
}
