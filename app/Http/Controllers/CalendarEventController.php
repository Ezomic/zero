<?php

namespace App\Http\Controllers;

use App\Actions\Mail\CreateCalendarEvent;
use App\Exceptions\CalendarUnavailableException;
use App\Models\Email;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function store(Request $request, Email $email, CreateCalendarEvent $createEvent): RedirectResponse
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['nullable', 'timezone:all'],
            'description' => ['nullable', 'string'],
        ]);

        $configuredTimezone = config('app.timezone');
        $timezone = $request->string('timezone')->toString() ?: (is_string($configuredTimezone) ? $configuredTimezone : 'UTC');

        try {
            $event = $createEvent->handle(
                email: $email,
                title: $request->string('title')->toString(),
                startsAt: CarbonImmutable::parse($request->string('starts_at')->toString(), $timezone),
                endsAt: CarbonImmutable::parse($request->string('ends_at')->toString(), $timezone),
                timezone: $timezone,
                description: $request->string('description')->toString() ?: null,
            );
        } catch (CalendarUnavailableException) {
            return back()->with('error', 'Calendar is unreachable — event not created.');
        }

        return back()
            ->with('status', 'Event created.')
            ->with('calendar_url', $event->url);
    }
}
