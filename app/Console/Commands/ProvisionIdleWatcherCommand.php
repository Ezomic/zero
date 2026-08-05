<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ProvisionIdleWatcherCommand extends Command
{
    protected $signature = 'mail:idle:provision {account : MailAccount ID to start watching}';

    protected $description = 'Set up the launchd/supervisor process that holds an IMAP IDLE connection for an account';

    /**
     * The counterpart to mail:idle:deprovision, and deliberately manual in the
     * same way (see THI-239). On production the idle watchers share
     * /etc/supervisor/conf.d/mail.conf with mail-queue, mail-scheduler and
     * mail-reverb, so rewriting that file from the app risks taking the other
     * three down on a bad edit; there this only prints the exact steps.
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
        $this->line('On production, idle watchers live in /etc/supervisor/conf.d/mail.conf alongside mail-queue, mail-scheduler and mail-reverb.');
        $this->newLine();
        $this->line('1. Add this block to that file:');
        $this->newLine();
        $this->line("[program:mail-idle-{$account->id}]");
        $this->line('command='.PHP_BINARY.' '.base_path('artisan')." mail:idle {$account->id}");
        $this->line('directory='.base_path());
        $this->line('autostart=true');
        $this->line('autorestart=true');
        $this->line('startsecs=10');
        $this->line('user=deploy');
        $this->line("stdout_logfile=/var/log/supervisor/mail-idle-{$account->id}.log");
        $this->line('redirect_stderr=true');
        $this->newLine();
        $this->line('2. sudo supervisorctl reread');
        $this->line('3. sudo supervisorctl update');
        $this->newLine();
        $this->line('These three sudo commands are already passwordless for the deploy user — see `sudo -l`.');

        return self::SUCCESS;
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
