<?php

namespace Tests\Unit\Mail;

use App\Support\UnsubscribeOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The header comes from whoever sent the mail, so the parser is fed the shapes
 * real senders use and the shapes an attacker would (ZERO-115).
 *
 * URLs here use literal IPs rather than hostnames so the guard is exercised
 * without a DNS lookup deciding whether the test passes.
 */
class UnsubscribeOptionsTest extends TestCase
{
    public function test_it_reads_an_https_uri(): void
    {
        $options = UnsubscribeOptions::parse('<https://example.com/unsub?id=1>');

        $this->assertSame('https://example.com/unsub?id=1', $options?->url);
        $this->assertNull($options?->mailto);
        $this->assertFalse($options?->oneClick);
    }

    public function test_it_reads_a_mailto_uri(): void
    {
        $options = UnsubscribeOptions::parse('<mailto:leave@example.com?subject=unsubscribe>');

        $this->assertSame('mailto:leave@example.com?subject=unsubscribe', $options?->mailto);
        $this->assertNull($options?->url);
    }

    public function test_it_keeps_both_when_the_sender_offers_both(): void
    {
        $options = UnsubscribeOptions::parse('<mailto:leave@example.com>, <https://example.com/unsub>');

        $this->assertSame('mailto:leave@example.com', $options?->mailto);
        $this->assertSame('https://example.com/unsub', $options?->url);
    }

    public function test_the_post_header_marks_it_one_click(): void
    {
        $options = UnsubscribeOptions::parse(
            '<https://93.184.216.34/unsub>',
            'List-Unsubscribe=One-Click',
        );

        $this->assertTrue($options?->oneClick);
        $this->assertTrue($options?->isPostable());
    }

    public function test_a_mailto_alone_is_never_one_click(): void
    {
        $options = UnsubscribeOptions::parse('<mailto:leave@example.com>', 'List-Unsubscribe=One-Click');

        $this->assertFalse($options?->oneClick);
        $this->assertFalse($options?->isPostable());
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function malformedHeaders(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'no brackets' => ['https://example.com/unsub'],
            'unclosed bracket' => ['<https://example.com/unsub'],
            'empty brackets' => ['<>'],
            'unknown scheme' => ['<ftp://example.com/unsub>'],
            'javascript uri' => ['<javascript:alert(1)>'],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function test_it_returns_null_for_a_header_it_cannot_use(?string $header): void
    {
        $this->assertNull(UnsubscribeOptions::parse($header, 'List-Unsubscribe=One-Click'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privateTargets(): array
    {
        return [
            'loopback' => ['<https://127.0.0.1/unsub>'],
            'private range' => ['<https://192.168.1.10/unsub>'],
            'link local' => ['<https://169.254.169.254/latest/meta-data/>'],
            'ipv6 loopback' => ['<https://[::1]/unsub>'],
            'plain http' => ['<http://93.184.216.34/unsub>'],
        ];
    }

    #[DataProvider('privateTargets')]
    public function test_it_refuses_to_post_to_a_target_it_cannot_vouch_for(string $header): void
    {
        $options = UnsubscribeOptions::parse($header, 'List-Unsubscribe=One-Click');

        $this->assertFalse($options?->isPostable(), "{$header} should not be postable");
    }

    public function test_a_host_that_does_not_resolve_is_not_postable(): void
    {
        $options = UnsubscribeOptions::parse(
            '<https://zero-unsubscribe-should-not-resolve.invalid/unsub>',
            'List-Unsubscribe=One-Click',
        );

        $this->assertFalse($options?->isPostable());
    }
}
