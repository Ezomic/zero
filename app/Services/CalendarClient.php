<?php

namespace App\Services;

use App\Exceptions\CalendarUnavailableException;
use App\Models\Email;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Creates events in the Chronos calendar app from a mail message. The event
 * links back to the exact message via its durable ULID.
 */
class CalendarClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.calendar.url'))
            && filled(config('services.calendar.token'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CalendarUnavailableException
     */
    public function createEvent(
        Email $email,
        string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
        ?string $description = null,
    ): array {
        $base = rtrim((string) config('services.calendar.url'), '/');

        try {
            $response = Http::withToken((string) config('services.calendar.token'))
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->post($base.'/api/events', [
                    'title' => $title,
                    'description' => $description,
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'all_day' => false,
                    'timezone' => $timezone,
                    'source' => [
                        'app' => 'zero',
                        'type' => 'email',
                        'id' => $email->ulid,
                        'url' => route('inbox.showByUlid', $email->ulid),
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new CalendarUnavailableException('Calendar is unreachable.', previous: $e);
        }

        if ($response->failed()) {
            throw new CalendarUnavailableException('Calendar returned '.$response->status().'.');
        }

        return $response->json();
    }
}
