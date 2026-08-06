<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * The production half of this command only prints instructions, so the thing
 * worth testing is that it names a file and a program that actually exist.
 * It named a mail.conf retired with the old app name for months (ZERO-100).
 */
class DeprovisionIdleWatcherCommandTest extends TestCase
{
    public function test_it_names_the_supervisor_file_that_exists_on_production(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('The production branch only runs off Darwin.');
        }

        $this->artisan('mail:idle:deprovision', ['id' => 10])
            ->expectsOutputToContain('/etc/supervisor/conf.d/zero.conf')
            ->expectsOutputToContain('[program:zero-idle-10]')
            ->doesntExpectOutputToContain('mail.conf')
            ->doesntExpectOutputToContain('mail-idle-')
            ->assertExitCode(0);
    }

    public function test_on_macos_it_reports_nothing_to_do_when_no_plist_exists(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('The launchd branch only runs on Darwin.');
        }

        $home = sys_get_temp_dir().'/zero-idle-deprovision-'.uniqid();
        mkdir($home.'/Library/LaunchAgents', 0755, true);
        $realHome = getenv('HOME') ?: null;
        putenv("HOME={$home}");

        try {
            $this->artisan('mail:idle:deprovision', ['id' => 999])
                ->expectsOutputToContain('nothing to do')
                ->assertExitCode(0);
        } finally {
            putenv($realHome === null ? 'HOME' : "HOME={$realHome}");
            @rmdir($home.'/Library/LaunchAgents');
            @rmdir($home.'/Library');
            @rmdir($home);
        }
    }
}
