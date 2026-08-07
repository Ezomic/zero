<?php

namespace Tests\Unit\Mail;

use App\Support\StoredAttachmentName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoredAttachmentNameTest extends TestCase
{
    /**
     * The whole point: whatever the sender called it, the result has to be a
     * single path segment that cannot escape the message's own directory.
     *
     * @return array<string, array{string}>
     */
    public static function hostileNameProvider(): array
    {
        return [
            'parent traversal' => ['../../../../etc/passwd'],
            'deep traversal' => ['a/../../b/../../../c.txt'],
            'absolute path' => ['/etc/shadow'],
            'windows path' => ['..\\..\\windows\\system32\\config.sys'],
            'nested directories' => ['invoices/2026/august/bill.pdf'],
            'just dots' => ['..'],
            'single dot' => ['.'],
            'trailing slash' => ['folder/'],
            'null byte' => ["evil\0.txt"],
            'newline' => ["two\nlines.txt"],
        ];
    }

    #[DataProvider('hostileNameProvider')]
    public function test_a_hostile_name_becomes_one_safe_segment(string $original): void
    {
        $stored = StoredAttachmentName::for($original);

        $this->assertStringNotContainsString('/', $stored);
        $this->assertStringNotContainsString('\\', $stored);
        $this->assertStringNotContainsString('..', $stored);
        $this->assertStringNotContainsString("\0", $stored);
        $this->assertNotSame('', trim($stored));
        $this->assertSame($stored, basename($stored));
    }

    public function test_it_keeps_a_recognisable_slug_and_extension(): void
    {
        $stored = StoredAttachmentName::for('Quarterly Report FINAL.pdf');

        $this->assertStringStartsWith('quarterly-report-final-', $stored);
        $this->assertStringEndsWith('.pdf', $stored);
    }

    /**
     * Two attachments in one message can share a name. They used to share a
     * path too, so the second overwrote the first and both rows pointed at
     * the same file.
     */
    public function test_the_same_name_twice_produces_two_different_files(): void
    {
        $first = StoredAttachmentName::for('image.png');
        $second = StoredAttachmentName::for('image.png');

        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('.png', $first);
        $this->assertStringEndsWith('.png', $second);
    }

    public function test_a_name_with_no_extension_is_still_usable(): void
    {
        $stored = StoredAttachmentName::for('README');

        $this->assertStringStartsWith('readme-', $stored);
        $this->assertStringNotContainsString('.', $stored);
    }

    public function test_an_absent_or_empty_name_still_yields_something(): void
    {
        foreach ([null, '', '   '] as $original) {
            $stored = StoredAttachmentName::for($original);

            $this->assertStringStartsWith('attachment-', $stored);
        }
    }

    public function test_a_very_long_name_is_bounded(): void
    {
        $stored = StoredAttachmentName::for(str_repeat('a', 500).'.pdf');

        $this->assertLessThan(120, strlen($stored));
        $this->assertStringEndsWith('.pdf', $stored);
    }

    public function test_a_hostile_extension_is_not_carried_through(): void
    {
        $stored = StoredAttachmentName::for('payload.p h p/../x');

        $this->assertStringNotContainsString(' ', $stored);
        $this->assertStringNotContainsString('/', $stored);
    }
}
