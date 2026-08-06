<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class DeprovisionIdleWatcherCommand extends Command
{
    protected $signature = 'mail:idle:deprovision {id : MailAccount ID whose idle watcher should be removed}';

    protected $description = 'Remove the launchd/supervisor process watching a deleted or deactivated MailAccount';

    // Run this by hand right after deleting an account — it's a deliberate
    // manual step (see THI-239), not wired into account deletion itself. On
    // production, zero-idle-{id} lives alongside zero-queue/zero-queue-flags/
    // zero-scheduler/zero-reverb in the same zero.conf, so rewriting
    // that file automatically from a web request risks taking down the other
    // three workers on a bad edit. This only prints the exact steps instead.
    public function handle(): int
    {
        $id = $this->argument('id');

        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->deprovisionLocal($id);
        }

        $program = ProvisionIdleWatcherCommand::PROGRAM_PREFIX.$id;
        $conf = ProvisionIdleWatcherCommand::SUPERVISOR_CONF;

        $this->line("On production, {$program} is defined in {$conf} alongside zero-queue, zero-queue-flags, zero-scheduler and zero-reverb.");
        $this->newLine();
        $this->line("1. Remove the [program:{$program}] block from that file.");
        $this->line('2. sudo supervisorctl reread');
        $this->line('3. sudo supervisorctl update');
        $this->newLine();
        $this->line("Then drop {$program} from the restart list in scripts/deploy.sh, or the");
        $this->line('next deploy fails trying to restart a program that no longer exists.');
        $this->newLine();
        $this->line('The supervisorctl commands are already passwordless for the deploy user — see `sudo -l`.');

        return self::SUCCESS;
    }

    private function deprovisionLocal(string $id): int
    {
        $label = "nl.thijssensoftware.zero.idle.{$id}";
        $plist = getenv('HOME')."/Library/LaunchAgents/{$label}.plist";

        if (! file_exists($plist)) {
            $this->info("No plist found at {$plist} — nothing to do.");

            return self::SUCCESS;
        }

        $this->info("Unloading {$label}…");
        Process::run(['launchctl', 'unload', $plist]);

        unlink($plist);
        $this->info("Removed {$plist}.");

        $this->newLine();
        $this->line("Also remove \"{$label}\" from the AGENTS array in ~/bin/workers");
        $this->line('and its rotation entry in ~/Library/Logs/newsyslog-workers.conf.');

        return self::SUCCESS;
    }
}
