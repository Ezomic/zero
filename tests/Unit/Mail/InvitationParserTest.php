<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\InvitationParser;
use App\Support\CalendarInvitation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvitationParserTest extends TestCase
{
    private InvitationParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new InvitationParser;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/invitations/{$name}.ics"));
    }

    private function parse(string $name, string $fallbackTimezone = 'UTC'): ?CalendarInvitation
    {
        return $this->parser->parse($this->fixture($name), $fallbackTimezone);
    }

    public function test_it_reads_a_google_invitation(): void
    {
        $invitation = $this->parse('google-request');

        $this->assertNotNull($invitation);
        // The comma is escaped in the payload and must come back unescaped.
        $this->assertSame('Quarterly planning, part two', $invitation->title);
        $this->assertSame('Meeting room 3, Herengracht 12', $invitation->location);
        $this->assertSame('Sam Organiser', $invitation->organiser);
        $this->assertSame('Bring the roadmap.', $invitation->description);
        $this->assertSame('REQUEST', $invitation->method);
        $this->assertSame('Europe/Amsterdam', $invitation->timezone);
        $this->assertFalse($invitation->allDay);
        // 14:00 Amsterdam in August is 12:00 UTC.
        $this->assertSame('2026-08-12 12:00:00', $invitation->startsAt->utc()->toDateTimeString());
        $this->assertSame('2026-08-12 13:00:00', $invitation->endsAt->utc()->toDateTimeString());
    }

    /**
     * Exchange emits Windows zone names PHP cannot construct. The instant
     * still has to be right; only the label falls back.
     */
    public function test_it_handles_an_outlook_windows_timezone(): void
    {
        $invitation = $this->parse('outlook-windows-tz', 'Europe/Amsterdam');

        $this->assertNotNull($invitation);
        $this->assertSame('Design review', $invitation->title);
        $this->assertSame('Alex Example', $invitation->organiser);
        $this->assertSame('Europe/Amsterdam', $invitation->timezone);
        $this->assertSame('2026-08-12 08:00:00', $invitation->startsAt->utc()->toDateTimeString());
    }

    public function test_it_reads_an_all_day_invitation(): void
    {
        $invitation = $this->parse('all-day', 'Europe/Amsterdam');

        $this->assertNotNull($invitation);
        $this->assertTrue($invitation->allDay);
        $this->assertSame('Company offsite', $invitation->title);
        $this->assertStringContainsString('all day', $invitation->humanSpan());
    }

    public function test_it_derives_the_end_from_a_duration(): void
    {
        $invitation = $this->parse('duration-only');

        $this->assertNotNull($invitation);
        $this->assertSame('2026-08-12 09:00:00', $invitation->startsAt->utc()->toDateTimeString());
        $this->assertSame('2026-08-12 09:45:00', $invitation->endsAt->utc()->toDateTimeString());
    }

    public function test_it_flags_a_cancellation(): void
    {
        $invitation = $this->parse('cancellation');

        $this->assertNotNull($invitation);
        $this->assertTrue($invitation->isCancellation());
    }

    /**
     * A series arrives as a master plus overrides sharing one UID, and the
     * override can come first in the document. The master is the one to show.
     */
    public function test_a_recurring_invitation_uses_the_master_event_not_an_override(): void
    {
        $invitation = $this->parse('recurring-with-override');

        $this->assertNotNull($invitation);
        $this->assertSame('Weekly sync', $invitation->title);
        $this->assertSame('2026-08-12 07:00:00', $invitation->startsAt->utc()->toDateTimeString());
    }

    public function test_the_end_never_lands_at_or_before_the_start(): void
    {
        $invitation = $this->parser->parse(<<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VEVENT
            UID:backwards@example.com
            DTSTART:20260812T090000Z
            DTEND:20260812T080000Z
            SUMMARY:Time travel
            END:VEVENT
            END:VCALENDAR
            ICS);

        $this->assertNotNull($invitation);
        $this->assertTrue($invitation->endsAt->greaterThan($invitation->startsAt));
    }

    public function test_an_event_with_no_summary_still_parses(): void
    {
        $invitation = $this->parser->parse(<<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VEVENT
            UID:nameless@example.com
            DTSTART:20260812T090000Z
            DTEND:20260812T093000Z
            END:VEVENT
            END:VCALENDAR
            ICS);

        $this->assertNotNull($invitation);
        $this->assertSame('(no title)', $invitation->title);
    }

    /** @return array<string, array{string}> */
    public static function unusableProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["  \n  "],
            'not calendar data at all' => ['Dear Robbin, are you free on Tuesday?'],
            'html masquerading as an attachment' => ['<html><body>nope</body></html>'],
            'calendar with no event' => ["BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR"],
            'event with no start' => ["BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:x\nSUMMARY:No start\nEND:VEVENT\nEND:VCALENDAR"],
            'truncated mid-event' => ["BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nDTSTART:2026081"],
        ];
    }

    /**
     * Anything unusable must return null so the reading pane falls back to
     * the manual modal, rather than throwing mid-render.
     */
    #[DataProvider('unusableProvider')]
    public function test_unusable_payloads_return_null(string $payload): void
    {
        $this->assertNull($this->parser->parse($payload));
    }

    public function test_an_oversized_payload_is_refused_without_parsing(): void
    {
        $huge = "BEGIN:VCALENDAR\nVERSION:2.0\n".str_repeat("X-PADDING:junk\n", 60000).'END:VCALENDAR';

        $this->assertGreaterThan(512 * 1024, strlen($huge));
        $this->assertNull($this->parser->parse($huge));
    }
}
