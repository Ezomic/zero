<?php

namespace App\Services\Mail;

use App\Support\CalendarInvitation;
use Carbon\CarbonImmutable;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as ICalDateTime;
use Sabre\VObject\Property\ICalendar\Duration as ICalDuration;
use Sabre\VObject\Reader;

/**
 * Reads the first VEVENT out of an iCalendar payload.
 *
 * Parsing is sabre/vobject's job rather than this app's: RFC 5545 has line
 * folding, escaping, a dozen date forms and embedded VTIMEZONE definitions,
 * and an invitation is untrusted input from an arbitrary sender. A parser
 * that looks fine against one fixture and mangles a real Outlook invite is
 * the failure mode worth avoiding.
 *
 * Every read off a sabre node is narrowed with instanceof rather than cast:
 * its property access is magic and hands back mixed, so a malformed invite
 * otherwise turns into a type error mid-render. Anything unparseable returns
 * null and the reading pane falls back to the manual create-event modal.
 */
class InvitationParser
{
    /** Guard against a hostile or accidental multi-megabyte attachment. */
    protected const MAX_BYTES = 512 * 1024;

    /** An event with no stated end lasts this long. RFC 5545 leaves it open;
     *  a zero-length calendar entry helps nobody. */
    protected const DEFAULT_MINUTES = 30;

    protected const MINUTES_PER_DAY = 1440;

    public function parse(string $ics, string $fallbackTimezone = 'UTC'): ?CalendarInvitation
    {
        if (trim($ics) === '' || strlen($ics) > self::MAX_BYTES) {
            return null;
        }

        try {
            $document = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return null;
        }

        if (! $document instanceof VCalendar) {
            return null;
        }

        $event = $this->firstEvent($document);

        if ($event === null) {
            return null;
        }

        $dtStart = $event->__get('DTSTART');

        if (! $dtStart instanceof ICalDateTime) {
            return null;
        }

        try {
            $startValue = $dtStart->getDateTime();
        } catch (\Throwable) {
            return null;
        }

        if (! $startValue instanceof \DateTimeInterface) {
            return null;
        }

        $start = CarbonImmutable::instance($startValue);
        $allDay = strtoupper($this->parameter($dtStart, 'VALUE') ?? '') === 'DATE';
        $end = $this->end($event, $start, $allDay);

        return new CalendarInvitation(
            title: $this->text($event, 'SUMMARY') ?? '(no title)',
            startsAt: $start,
            endsAt: $end,
            timezone: $this->timezone($dtStart, $fallbackTimezone),
            allDay: $allDay,
            location: $this->text($event, 'LOCATION'),
            organiser: $this->organiser($event),
            description: $this->text($event, 'DESCRIPTION'),
            method: $this->text($document, 'METHOD'),
        );
    }

    /**
     * A recurring invitation arrives as a master event plus overrides sharing
     * one UID. The master carries the series' own start, so skip anything with
     * a RECURRENCE-ID rather than letting document order decide.
     */
    protected function firstEvent(VCalendar $document): ?Component
    {
        $fallback = null;

        foreach ($document->select('VEVENT') as $event) {
            if (! $event instanceof Component) {
                continue;
            }

            $fallback ??= $event;

            if ($event->__get('RECURRENCE-ID') === null) {
                return $event;
            }
        }

        return $fallback;
    }

    /**
     * DTEND wins; failing that DURATION; failing that a default length. An
     * end at or before the start is nonsense on a calendar and Chronos
     * rejects it anyway, so that collapses to the default too.
     */
    protected function end(Component $event, CarbonImmutable $start, bool $allDay): CarbonImmutable
    {
        $default = $start->addMinutes($allDay ? self::MINUTES_PER_DAY : self::DEFAULT_MINUTES);
        $dtEnd = $event->__get('DTEND');

        if ($dtEnd instanceof ICalDateTime) {
            try {
                $value = $dtEnd->getDateTime();
            } catch (\Throwable) {
                $value = null;
            }

            if ($value instanceof \DateTimeInterface) {
                $end = CarbonImmutable::instance($value);

                return $end->greaterThan($start) ? $end : $default;
            }
        }

        $duration = $event->__get('DURATION');

        if ($duration instanceof ICalDuration) {
            try {
                $end = $start->add($duration->getDateInterval());
            } catch (\Throwable) {
                return $default;
            }

            return $end->greaterThan($start) ? $end : $default;
        }

        return $default;
    }

    /**
     * TZID on DTSTART is the zone the organiser meant. Its absence means the
     * value was UTC or floating; the caller's own zone is the best guess for
     * a floating time and is right for UTC either way.
     */
    protected function timezone(ICalDateTime $dtStart, string $fallback): string
    {
        $tzid = $this->parameter($dtStart, 'TZID');

        if ($tzid === null) {
            return $fallback;
        }

        try {
            new \DateTimeZone($tzid);
        } catch (\Throwable) {
            // Outlook emits Windows zone names ("W. Europe Standard Time")
            // PHP does not know. Not worth a mapping table: the instant sabre
            // resolved is already correct, only the label would differ.
            return $fallback;
        }

        return $tzid;
    }

    protected function organiser(Component $event): ?string
    {
        $organiser = $event->__get('ORGANIZER');

        if (! $organiser instanceof Property) {
            return null;
        }

        $name = $this->parameter($organiser, 'CN');

        if ($name !== null && trim($name) !== '') {
            return trim($name);
        }

        $raw = $organiser->getValue();
        $value = is_string($raw) ? trim($raw) : '';

        if ($value === '') {
            return null;
        }

        return str_starts_with(strtolower($value), 'mailto:') ? substr($value, 7) : $value;
    }

    protected function parameter(Property $property, string $name): ?string
    {
        $parameter = $property->offsetGet($name);

        return $parameter instanceof Parameter ? (string) $parameter : null;
    }

    protected function text(Component $component, string $property): ?string
    {
        $found = $component->__get($property);

        if (! $found instanceof Property) {
            return null;
        }

        $raw = $found->getValue();
        $value = is_string($raw) ? trim($raw) : '';

        return $value === '' ? null : $value;
    }
}
