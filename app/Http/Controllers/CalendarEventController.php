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

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['nullable', 'timezone:all'],
            'description' => ['nullable', 'string'],
        ]);

        $timezone = $data['timezone'] ?? config('app.timezone');

        try {
            $result = $calendar->createEvent(
                email: $email,
                title: $data['title'],
                startsAt: CarbonImmutable::parse($data['starts_at'], $timezone),
                endsAt: CarbonImmutable::parse($data['ends_at'], $timezone),
                timezone: $timezone,
                description: $data['description'] ?? null,
            );
        } catch (CalendarUnavailableException) {
            return back()->with('error', 'Calendar is unreachable — event not created.');
        }

        return back()
            ->with('status', 'Event created.')
            ->with('calendar_url', $result['url'] ?? null);
    }
}
