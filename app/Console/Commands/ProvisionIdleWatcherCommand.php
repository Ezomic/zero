<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ProvisionIdleWatcherCommand extends Command
{
    protected $signature = 'mail:idle:provision {account : MailAccount ID to start watching}';

    protected $description = 'Set up the launchd job (local) or systemd unit (production) that holds an IMAP IDLE connection for an account';

    /** Where production's workers are declared: one entry per worker in Ezomic/infra. */
    public const APPS_FILE = 'ansible/group_vars/all/apps.yml';

    /** A worker named idle-<id> becomes zero-idle-<id>.service, which is what
     *  app-deploy restarts after a release. Anything else is never restarted. */
    public const PROGRAM_PREFIX = 'zero-idle-';

    /**
     * The counterpart to mail:idle:deprovision, and deliberately manual in the
     * same way (see THI-239). On production the server is config as code: an
     * app cannot edit the playbook that defines it, so this prints the exact
     * entry to add and the command that applies it.
     */
    public function handle(): int
    {
        $account = MailAccount::find($this->argument('account'));

        if (! $account) {
            $this->error("Account {$this->argument('account')} does not exist.");

            return self::FAILURE;
        }

        if ($account->provider === MailAccount::PROVIDER_OUTLOOK) {
            $this->error("{$account->email_address} reads via Microsoft Graph, which has no IMAP IDLE equivalent. Nothing to provision — it syncs on the 5-minute schedule.");

            return self::FAILURE;
        }

        if (! $account->is_active) {
            $this->error("{$account->email_address} is inactive. Fix its credentials and re-enable it first, or the watcher will exit immediately.");

            return self::FAILURE;
        }

        if (! config('features.imap_idle')) {
            $this->warn('IMAP IDLE is disabled (FEATURE_IMAP_IDLE=false), so the watcher would exit on startup. Provisioning anyway.');
        }

        return $this->osFamily() === 'Darwin'
            ? $this->provisionLocal($account)
            : $this->printProductionSteps($account);
    }

    /** Seam so both branches stay testable from either OS. */
    protected function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    protected function provisionLocal(MailAccount $account): int
    {
        $label = "nl.thijssensoftware.zero.idle.{$account->id}";
        $home = (string) getenv('HOME');
        $plist = "{$home}/Library/LaunchAgents/{$label}.plist";

        if (file_exists($plist)) {
            $this->info("{$label} is already provisioned at {$plist} — nothing to do.");

            return self::SUCCESS;
        }

        file_put_contents($plist, $this->plist($label, $account, $home));
        $this->info("Wrote {$plist}.");

        $result = Process::run(['launchctl', 'load', $plist]);

        if (! $result->successful()) {
            $this->error('launchctl load failed: '.trim($result->errorOutput()));

            return self::FAILURE;
        }

        $this->info("Loaded {$label}, now watching {$account->email_address}.");
        $this->newLine();
        $this->line("Also add \"{$label}\" to the AGENTS array in ~/bin/workers");
        $this->line('and a rotation entry in ~/Library/Logs/newsyslog-workers.conf.');

        return self::SUCCESS;
    }

    protected function printProductionSteps(MailAccount $account): int
    {
        $program = self::PROGRAM_PREFIX.$account->id;

        $this->line('On production the watchers are systemd units generated from Ezomic/infra, alongside zero-queue, zero-queue-flags, zero-schedule and zero-reverb.');
        $this->newLine();
        $this->line('1. Add this worker to the zero entry in '.self::APPS_FILE.':');
        $this->newLine();

        foreach ($this->workerEntry($account) as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line('2. ansible-playbook site.yml --tags apps');
        $this->newLine();
        $this->line("That renders {$program}.service and starts it. app-deploy restarts every");
        $this->line('zero-*.service after a release, so it always runs the code that is live.');

        return self::SUCCESS;
    }

    /**
     * The shape apps.yml already uses for zero's other workers: a name, and the
     * artisan arguments to run. Everything else (user, sandbox, restarts, log
     * handling) comes from the worker template in the playbook.
     *
     * @return list<string>
     */
    protected function workerEntry(MailAccount $account): array
    {
        return [
            '      - {name: idle-'.$account->id.', artisan: "mail:idle '.$account->id.'"}',
        ];
    }

    protected function plist(string $label, MailAccount $account, string $home): string
    {
        $php = htmlspecialchars(PHP_BINARY, ENT_XML1);
        $cwd = htmlspecialchars(base_path(), ENT_XML1);
        $log = htmlspecialchars("{$home}/Library/Logs/zero-idle-{$account->id}.log", ENT_XML1);

        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>Label</key>
            <string>{$label}</string>
            <key>ProgramArguments</key>
            <array>
                <string>{$php}</string>
                <string>artisan</string>
                <string>mail:idle</string>
                <string>{$account->id}</string>
            </array>
            <key>WorkingDirectory</key>
            <string>{$cwd}</string>
            <key>RunAtLoad</key>
            <true/>
            <key>KeepAlive</key>
            <true/>
            <key>ThrottleInterval</key>
            <integer>10</integer>
            <key>StandardOutPath</key>
            <string>{$log}</string>
            <key>StandardErrorPath</key>
            <string>{$log}</string>
        </dict>
        </plist>

        PLIST;
    }
}
