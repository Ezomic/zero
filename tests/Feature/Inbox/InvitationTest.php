<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->user = User::factory()->create();

        config([
            'services.calendar.url' => 'https://chronos.test',
            'services.calendar.token' => 'test-token',
        ]);
    }

    private function emailWithAttachment(string $filename, string $contents, ?string $mime): Email
    {
        $account = MailAccount::factory()->create(['user_id' => $this->user->id]);
        $email = Email::factory()->create([
            'mail_account_id' => $account->id,
            'subject' => 'Invitation: Quarterly planning',
        ]);

        $path = "email-attachments/{$account->id}/{$email->id}/{$filename}";
        Storage::disk('local')->put($path, $contents);

        EmailAttachment::create([
            'email_id' => $email->id,
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => strlen($contents),
            'storage_path' => $path,
        ]);

        return $email->fresh();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/invitations/{$name}.ics"));
    }

    public function test_the_reading_pane_surfaces_an_invitation(): void
    {
        $email = $this->emailWithAttachment('invite.ics', $this->fixture('google-request'), 'text/calendar');

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee('Invitation')
            ->assertSee('Quarterly planning, part two')
            ->assertSee('Meeting room 3, Herengracht 12')
            ->assertSee('Organised by Sam Organiser')
            ->assertSee('Add to calendar');
    }

    /**
     * Plenty of senders attach a bare invite.ics as application/octet-stream,
     * so the extension has to count as a signal on its own.
     */
    public function test_an_ics_attachment_with_a_generic_mime_type_is_still_detected(): void
    {
        $email = $this->emailWithAttachment('invite.ics', $this->fixture('google-request'), 'application/octet-stream');

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee('Quarterly planning, part two');
    }

    public function test_a_cancellation_is_shown_but_offers_nothing_to_add(): void
    {
        $email = $this->emailWithAttachment('invite.ics', $this->fixture('cancellation'), 'text/calendar');

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee('Cancelled invitation')
            ->assertSee('nothing to add')
            ->assertDontSee('Add to calendar');
    }

    /**
     * The ticket's own requirement: a malformed invite degrades to the
     * existing manual modal rather than erroring.
     */
    public function test_an_unparseable_attachment_falls_back_to_the_manual_modal(): void
    {
        $email = $this->emailWithAttachment('invite.ics', 'this is not a calendar', 'text/calendar');

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertDontSee('Add to calendar')
            ->assertSee('Create event');
    }

    public function test_a_message_with_an_ordinary_attachment_shows_no_invitation(): void
    {
        $email = $this->emailWithAttachment('report.pdf', '%PDF-1.4 fake', 'application/pdf');

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertDontSee('Add to calendar');
    }

    public function test_an_attachment_row_whose_file_is_gone_shows_no_invitation(): void
    {
        $email = $this->emailWithAttachment('invite.ics', $this->fixture('google-request'), 'text/calendar');
        Storage::disk('local')->delete($email->attachments->first()->storage_path);

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertDontSee('Add to calendar');
    }

    public function test_adding_an_invitation_sends_the_parsed_times_to_chronos(): void
    {
        Http::fake([
            'chronos.test/api/events' => Http::response(['id' => 99, 'url' => 'https://chronos.test/events/99'], 201),
        ]);

        $email = $this->emailWithAttachment('invite.ics', $this->fixture('google-request'), 'text/calendar');

        $this->actingAs($this->user)->post(route('inbox.calendarEvent', $email), [
            'title' => 'Quarterly planning, part two',
            'starts_at' => '2026-08-12T14:00',
            'ends_at' => '2026-08-12T15:00',
            'timezone' => 'Europe/Amsterdam',
        ])->assertRedirect();

        Http::assertSent(function (Request $request) {
            return $request['title'] === 'Quarterly planning, part two'
                && str_starts_with($request['starts_at'], '2026-08-12T14:00:00+02:00')
                && $request['timezone'] === 'Europe/Amsterdam';
        });

        // Goes through the same recording path as a hand-made event (ZERO-94).
        $this->assertSame('99', $email->calendarEvents()->sole()->remote_id);
    }

    public function test_the_add_form_carries_the_invitations_own_times(): void
    {
        $email = $this->emailWithAttachment('invite.ics', $this->fixture('google-request'), 'text/calendar');

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee('value="2026-08-12T14:00"', false)
            ->assertSee('value="2026-08-12T15:00"', false)
            ->assertSee('value="Europe/Amsterdam"', false);
    }

    public function test_another_user_cannot_see_the_invitation(): void
    {
        $email = $this->emailWithAttachment('invite.ics', $this->fixture('google-request'), 'text/calendar');

        $this->actingAs(User::factory()->create())
            ->get(route('inbox.panel', $email))
            ->assertForbidden();
    }
}
