<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The parts of an iCalendar VEVENT this app can act on.
 *
 * Deliberately flat and small: everything here maps onto a field the Chronos
 * create form already takes, so an invitation can be handed straight to it.
 * Recurrence, attendee lists and free/busy are not modelled — an invite
 * carrying them still yields its first occurrence, which is what a one-click
 * "add to calendar" needs.
 */
final class CalendarInvitation
{
    public function __construct(
        public readonly string $title,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly string $timezone,
        public readonly bool $allDay = false,
        public readonly ?string $location = null,
        public readonly ?string $organiser = null,
        public readonly ?string $description = null,
        public readonly ?string $method = null,
    ) {}

    /**
     * A cancellation is still worth surfacing (the meeting is off), but it is
     * not something to offer as an event to create.
     */
    public function isCancellation(): bool
    {
        return strtoupper((string) $this->method) === 'CANCEL';
    }

    public function startsAtInOwnZone(): CarbonImmutable
    {
        return $this->startsAt->setTimezone($this->timezone);
    }

    public function endsAtInOwnZone(): CarbonImmutable
    {
        return $this->endsAt->setTimezone($this->timezone);
    }

    /** Human-readable span for the invitation block, e.g. "Mon 10 Aug, 09:00 – 09:30". */
    public function humanSpan(): string
    {
        $start = $this->startsAtInOwnZone();
        $end = $this->endsAtInOwnZone();

        if ($this->allDay) {
            return $start->isSameDay($end->subDay())
                ? $start->format('D j M').' (all day)'
                : $start->format('D j M').' to '.$end->subDay()->format('D j M').' (all day)';
        }

        return $start->isSameDay($end)
            ? $start->format('D j M, H:i').' – '.$end->format('H:i')
            : $start->format('D j M, H:i').' – '.$end->format('D j M, H:i');
    }
}
