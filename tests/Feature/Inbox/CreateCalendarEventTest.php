<?php

namespace Tests\Feature\Inbox;

use App\Models\CalendarEvent;
use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateCalendarEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.calendar.url' => 'https://chronos.test',
            'services.calendar.token' => 'test-token',
        ]);
    }

    private function emailFor(User $user): Email
    {
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        return Email::factory()->create([
            'mail_account_id' => $account->id,
            'subject' => 'Project sync',
        ]);
    }

    public function test_it_creates_an_event_linking_back_by_ulid(): void
    {
        Http::fake([
            'chronos.test/api/events' => Http::response(['url' => 'https://chronos.test/calendar?date=2026-07-20'], 201),
        ]);

        $user = User::factory()->create();
        $email = $this->emailFor($user);

        $this->actingAs($user)
            ->post(route('inbox.calendarEvent', $email), [
                'title' => 'Project sync',
                'starts_at' => '2026-07-20T09:00',
                'ends_at' => '2026-07-20T09:30',
                'timezone' => 'Europe/Amsterdam',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request) use ($email) {
            return $request->url() === 'https://chronos.test/api/events'
                && $request['source']['id'] === $email->ulid
                && $request['source']['app'] === 'zero'
                && str_contains($request['source']['url'], $email->ulid);
        });
    }

    public function test_it_records_what_chronos_created(): void
    {
        Http::fake([
            'chronos.test/api/events' => Http::response([
                'id' => 4711,
                'url' => 'https://chronos.test/events/4711',
            ], 201),
        ]);

        $user = User::factory()->create();
        $email = $this->emailFor($user);

        $this->actingAs($user)->post(route('inbox.calendarEvent', $email), [
            'title' => 'Project sync',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T09:30',
            'timezone' => 'Europe/Amsterdam',
        ])->assertRedirect();

        $event = $email->calendarEvents()->sole();

        $this->assertSame('4711', $event->remote_id);
        $this->assertSame('https://chronos.test/events/4711', $event->url);
        $this->assertSame('Project sync', $event->title);
        $this->assertSame('Europe/Amsterdam', $event->timezone);
        $this->assertSame('2026-07-20 07:00:00', $event->starts_at->utc()->toDateTimeString());
    }

    public function test_a_failed_create_records_nothing(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $user = User::factory()->create();
        $email = $this->emailFor($user);

        $this->actingAs($user)->post(route('inbox.calendarEvent', $email), [
            'title' => 'Project sync',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T09:30',
            'timezone' => 'Europe/Amsterdam',
        ])->assertRedirect();

        $this->assertSame(0, $email->calendarEvents()->count());
    }

    public function test_a_chronos_response_without_an_id_still_records_the_event(): void
    {
        Http::fake([
            'chronos.test/api/events' => Http::response(['url' => 'https://chronos.test/calendar'], 201),
        ]);

        $user = User::factory()->create();
        $email = $this->emailFor($user);

        $this->actingAs($user)->post(route('inbox.calendarEvent', $email), [
            'title' => 'Project sync',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T09:30',
            'timezone' => 'Europe/Amsterdam',
        ])->assertRedirect();

        $event = $email->calendarEvents()->sole();

        $this->assertNull($event->remote_id);
        $this->assertSame('https://chronos.test/calendar', $event->url);
    }

    public function test_the_reading_pane_links_an_event_already_created_from_the_message(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $email = $this->emailFor($user);

        CalendarEvent::create([
            'email_id' => $email->id,
            'remote_id' => '4711',
            'url' => 'https://chronos.test/events/4711',
            'title' => 'Project sync',
            'starts_at' => '2026-07-20 09:00:00',
            'ends_at' => '2026-07-20 09:30:00',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $this->actingAs($user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee('https://chronos.test/events/4711', false)
            ->assertSee('Create another event')
            ->assertSee('Submitting will create another')
            // Stored as 09:00 UTC, shown in the event's own zone.
            ->assertSee('20 Jul, 11:00');
    }

    public function test_a_message_with_no_events_offers_a_plain_create(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $email = $this->emailFor($user);

        $this->actingAs($user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee('Create event')
            ->assertDontSee('Create another event')
            ->assertDontSee('Submitting will create another');
    }

    public function test_it_flashes_an_error_and_does_not_500_when_calendar_is_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $user = User::factory()->create();
        $email = $this->emailFor($user);

        $response = $this->actingAs($user)->post(route('inbox.calendarEvent', $email), [
            'title' => 'Project sync',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T09:30',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_it_forbids_creating_an_event_from_another_users_email(): void
    {
        Http::fake();

        $email = $this->emailFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post(route('inbox.calendarEvent', $email), [
                'title' => 'Nope',
                'starts_at' => '2026-07-20T09:00',
                'ends_at' => '2026-07-20T09:30',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
