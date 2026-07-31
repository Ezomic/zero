<?php

namespace App\Http\Controllers;

use App\Exceptions\CalendarUnavailableException;
use App\Models\Email;
use App\Services\CalendarClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function store(Request $request, Email $email, CalendarClient $calendar): RedirectResponse
    {
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['nullable', 'timezone:all'],
            'description' => ['nullable', 'string'],
        ]);

        $data = [];

        foreach (is_array($validated) ? $validated : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        $configuredTimezone = config('app.timezone');
        $timezone = $request->string('timezone')->toString() ?: (is_string($configuredTimezone) ? $configuredTimezone : 'UTC');

        try {
            $result = $calendar->createEvent(
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
            ->with('calendar_url', $result['url'] ?? null);
    }
}
