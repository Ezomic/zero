<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class DeprovisionIdleWatcherCommand extends Command
{
    protected $signature = 'mail:idle:deprovision {id : MailAccount ID whose idle watcher should be removed}';

    protected $description = 'Remove the launchd job (local) or systemd unit (production) watching a deleted or deactivated MailAccount';

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
        $appsFile = ProvisionIdleWatcherCommand::APPS_FILE;

        $this->line("On production, {$program}.service is generated from Ezomic/infra alongside zero-queue, zero-queue-flags, zero-schedule and zero-reverb.");
        $this->newLine();
        $this->line("1. Remove the idle-{$id} worker from the zero entry in {$appsFile}.");
        $this->line('2. ansible-playbook site.yml --tags apps');
        $this->newLine();
        $this->line('The playbook renders units, it does not remove them, so also stop and delete the');
        $this->line("leftover unit on the server: systemctl disable --now {$program} && rm /etc/systemd/system/{$program}.service && systemctl daemon-reload");

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
