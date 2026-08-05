<?php

namespace App\Actions\Mail;

use App\Exceptions\CalendarUnavailableException;
use App\Models\CalendarEvent;
use App\Models\Email;
use App\Services\CalendarClient;
use App\Support\Payload;
use Carbon\CarbonImmutable;

class CreateCalendarEvent
{
    public function __construct(
        protected CalendarClient $calendar,
    ) {}

    /**
     * Creates the event in Chronos and records what was created.
     *
     * The local row is only written once Chronos has confirmed, so a failed
     * call leaves nothing behind to link to. Chronos remains the owner of the
     * event: nothing here tries to keep the two in step afterwards, and a
     * deleted or edited event over there will leave this row stale.
     *
     * @throws CalendarUnavailableException
     */
    public function handle(
        Email $email,
        string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
        ?string $description = null,
    ): CalendarEvent {
        $created = $this->calendar->createEvent(
            email: $email,
            title: $title,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            description: $description,
        );

        return CalendarEvent::create([
            'email_id' => $email->id,
            'remote_id' => $this->remoteId($created),
            'url' => Payload::nullableStr($created, 'url'),
            'title' => $title,
            // Stored in UTC like every other timestamp here; `timezone`
            // carries the zone the user actually picked, which is what the
            // reading pane renders it back in.
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
            'timezone' => $timezone,
        ]);
    }

    /**
     * Chronos may key the event by either name, and an id that came back as a
     * number is still an id.
     *
     * @param  array<string, mixed>  $created
     */
    private function remoteId(array $created): ?string
    {
        $id = $created['id'] ?? $created['uuid'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }
}
