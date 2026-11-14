<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\ImapClientFactory;
use App\Services\Mail\ImapSyncService;
use App\Services\Mail\OAuthTokenRefresher;
use Mockery;
use Tests\TestCase;

class ScriptExposingImapSyncService extends ImapSyncService
{
    public function script(string $from, string $subject): string
    {
        return $this->macOsNotificationScript($from, $subject);
    }
}

class MacOsNotificationScriptTest extends TestCase
{
    private function script(string $from, string $subject): string
    {
        $service = new ScriptExposingImapSyncService(
            new ImapClientFactory(Mockery::mock(OAuthTokenRefresher::class)),
        );

        return $service->script($from, $subject);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * Reads one AppleScript string literal at $offset the way the AppleScript
     * compiler does and returns its value and the offset just past it.
     *
     * @return array{0: string, 1: int}
     */
    private function readLiteral(string $script, int $offset): array
    {
        $this->assertSame('"', $script[$offset], 'expected a string literal');
        $value = '';

        for ($i = $offset + 1; $i < strlen($script); $i++) {
            $char = $script[$i];

            if ($char === '"') {
                return [$value, $i + 1];
            }

            if ($char === '\\') {
                $next = $script[++$i];
                $this->assertContains($next, ['\\', '"'], 'unexpected escape sequence');
                $value .= $next;

                continue;
            }

            $value .= $char;
        }

        $this->fail('string literal never closed');
    }

    /**
     * @return array{subject: string, from: string}
     */
    private function parse(string $script): array
    {
        $prefix = 'display notification ';
        $this->assertStringStartsWith($prefix, $script);
        [$subject, $offset] = $this->readLiteral($script, strlen($prefix));

        $middle = ' with title "New mail" subtitle ';
        $this->assertSame($middle, substr($script, $offset, strlen($middle)));
        [$from, $offset] = $this->readLiteral($script, $offset + strlen($middle));

        $this->assertSame(' sound name "Ping"', substr($script, $offset), 'script continues past the sender');

        return ['subject' => $subject, 'from' => $from];
    }

    public function test_a_backslash_quote_in_the_subject_cannot_close_the_string(): void
    {
        $subject = 'x\\" & (do shell script (character id {116,111,117,99,104})) --';

        $parsed = $this->parse($this->script('Mallory', $subject));

        $this->assertSame($subject, $parsed['subject']);
        $this->assertSame('Mallory', $parsed['from']);
    }

    public function test_the_sender_name_is_kept_as_data_too(): void
    {
        $from = 'Eve \\\\" & (do shell script "id") & "';

        $parsed = $this->parse($this->script($from, 'Hello'));

        $this->assertSame($from, $parsed['from']);
    }

    public function test_control_characters_are_dropped(): void
    {
        $parsed = $this->parse($this->script("A\rB", "line one\nline two\t\x00end"));

        $this->assertSame('line oneline twoend', $parsed['subject']);
        $this->assertSame('AB', $parsed['from']);
    }

    public function test_ordinary_text_is_unchanged(): void
    {
        $parsed = $this->parse($this->script('Ana "the boss"', 'Café: 50% off \\o/'));

        $this->assertSame('Café: 50% off \\o/', $parsed['subject']);
        $this->assertSame('Ana "the boss"', $parsed['from']);
    }
}
