<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ProvisionIdleWatcherCommand;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ProvisionIdleWatcherCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $home;

    private ?string $realHome = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The command writes into $HOME/Library/LaunchAgents, so point HOME at
        // a scratch directory rather than the developer's real one.
        $this->realHome = getenv('HOME') ?: null;
        $this->home = sys_get_temp_dir().'/zero-idle-provision-'.uniqid();
        File::makeDirectory($this->home.'/Library/LaunchAgents', 0755, true);
        File::makeDirectory($this->home.'/Library/Logs', 0755, true);
        putenv("HOME={$this->home}");
    }

    protected function tearDown(): void
    {
        putenv($this->realHome === null ? 'HOME' : "HOME={$this->realHome}");
        File::deleteDirectory($this->home);

        parent::tearDown();
    }

    /**
     * Both OS branches must stay covered wherever the suite runs: development
     * is macOS and CI is Linux, so pinning either to the host would leave the
     * other untested exactly where it matters.
     */
    private function pretendOs(string $family): void
    {
        $command = new class($family) extends ProvisionIdleWatcherCommand
        {
            public function __construct(private string $family)
            {
                parent::__construct();
            }

            protected function osFamily(): string
            {
                return $this->family;
            }
        };

        $this->app->make(Kernel::class)->registerCommand($command);
    }

    private function account(array $attributes = []): MailAccount
    {
        return MailAccount::factory()->create([
            'user_id' => User::factory(),
            'provider' => MailAccount::PROVIDER_IMAP,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function plistPath(MailAccount $account): string
    {
        return "{$this->home}/Library/LaunchAgents/nl.thijssensoftware.zero.idle.{$account->id}.plist";
    }

    public function test_it_fails_for_an_account_that_does_not_exist(): void
    {
        $this->artisan('mail:idle:provision', ['account' => 999999])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    public function test_it_refuses_an_outlook_account(): void
    {
        $account = $this->account(['provider' => MailAccount::PROVIDER_OUTLOOK]);

        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain('no IMAP IDLE equivalent')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->plistPath($account));
    }

    public function test_it_refuses_an_inactive_account(): void
    {
        $account = $this->account(['is_active' => false]);

        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain('is inactive')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->plistPath($account));
    }

    public function test_on_macos_it_writes_the_plist_and_loads_it(): void
    {
        Process::fake();
        $this->pretendOs('Darwin');
        $account = $this->account(['email_address' => 'watched@example.com']);

        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain('watched@example.com')
            ->assertExitCode(0);

        $this->assertFileExists($this->plistPath($account));

        $plist = (string) file_get_contents($this->plistPath($account));
        $this->assertStringContainsString("<string>nl.thijssensoftware.zero.idle.{$account->id}</string>", $plist);
        $this->assertStringContainsString('<string>mail:idle</string>', $plist);
        $this->assertStringContainsString("<string>{$account->id}</string>", $plist);
        $this->assertStringContainsString('<key>KeepAlive</key>', $plist);
        $this->assertStringContainsString(base_path(), $plist);

        Process::assertRan(fn ($process) => $process->command === ['launchctl', 'load', $this->plistPath($account)]);
    }

    public function test_the_generated_plist_is_valid_xml(): void
    {
        Process::fake();
        $this->pretendOs('Darwin');
        $account = $this->account();

        $this->artisan('mail:idle:provision', ['account' => $account->id])->assertExitCode(0);

        $this->assertNotFalse(
            simplexml_load_file($this->plistPath($account)),
            'the plist must parse as XML',
        );
    }

    public function test_provisioning_twice_is_a_no_op(): void
    {
        Process::fake();
        $this->pretendOs('Darwin');
        $account = $this->account();

        $this->artisan('mail:idle:provision', ['account' => $account->id])->assertExitCode(0);
        $written = filemtime($this->plistPath($account));

        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain('already provisioned')
            ->assertExitCode(0);

        $this->assertSame($written, filemtime($this->plistPath($account)));
        Process::assertRanTimes(fn ($process) => $process->command[0] === 'launchctl', 1);
    }

    public function test_it_reports_a_failed_launchctl_load(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'Load failed: 5: Input/output error', exitCode: 1),
        ]);
        $this->pretendOs('Darwin');
        $account = $this->account();

        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain('launchctl load failed')
            ->assertExitCode(1);
    }

    public function test_on_linux_it_prints_the_supervisor_steps_without_touching_the_filesystem(): void
    {
        Process::fake();
        $this->pretendOs('Linux');
        $account = $this->account();

        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain("[program:zero-idle-{$account->id}]")
            ->expectsOutputToContain('/etc/supervisor/conf.d/zero.conf')
            ->expectsOutputToContain('supervisorctl reread')
            ->expectsOutputToContain('supervisorctl update')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->plistPath($account));
        Process::assertNothingRan();
    }

    /**
     * The instructions once named a mail.conf that no longer exists, with
     * mail-idle-* programs the deploy script would never restart (ZERO-100).
     * Both halves are pinned here so they cannot drift apart again.
     */
    public function test_the_printed_program_name_is_one_the_deploy_script_restarts(): void
    {
        Process::fake();
        $this->pretendOs('Linux');
        $account = $this->account();

        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain('zero-idle-')
            ->doesntExpectOutputToContain('mail.conf')
            ->doesntExpectOutputToContain('mail-idle-')
            ->assertExitCode(0);

        $deployScript = (string) file_get_contents(base_path('scripts/deploy.sh'));
        $this->assertStringContainsString('zero-idle-', $deployScript);
    }

    public function test_the_printed_block_matches_the_shape_of_the_existing_programs(): void
    {
        Process::fake();
        $this->pretendOs('Linux');
        $account = $this->account();

        // Same knobs the sibling programs in zero.conf are configured with,
        // so a watcher added from this output behaves like the others.
        $this->artisan('mail:idle:provision', ['account' => $account->id])
            ->expectsOutputToContain('process_name=%(program_name)s_%(process_num)02d')
            ->expectsOutputToContain('command=php '.base_path('artisan')." mail:idle {$account->id}")
            ->expectsOutputToContain('stopasgroup=true')
            ->expectsOutputToContain('user=deploy')
            ->expectsOutputToContain(base_path("storage/logs/idle-{$account->id}.log"))
            ->assertExitCode(0);
    }
}
