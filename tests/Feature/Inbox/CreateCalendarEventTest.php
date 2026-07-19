<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
