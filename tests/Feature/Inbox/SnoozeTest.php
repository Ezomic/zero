<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\User;
use App\Support\SnoozePresets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SnoozeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->account = MailAccount::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function email(array $attributes = []): Email
    {
        return Email::factory()->create([
            'mail_account_id' => $this->account->id,
            'folder' => 'INBOX',
            'uid' => (string) fake()->unique()->numberBetween(1, 999999),
            'is_deleted' => false,
            'is_archived' => false,
            'is_read' => false,
            'body_text' => 'Body so opening it does not reach for IMAP.',
            ...$attributes,
        ]);
    }

    private function snooze(Email $email, array $payload)
    {
        return $this->actingAs($this->user)
            ->from(route('inbox.show', $email))
            ->post(route('inbox.snooze', $email), $payload);
    }

    public function test_a_snoozed_conversation_leaves_the_inbox(): void
    {
        $email = $this->email(['subject' => 'Deal with later']);
        $other = $this->email(['subject' => 'Deal with now']);

        $this->snooze($email, ['preset' => SnoozePresets::TOMORROW])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHas('status');

        $this->actingAs($this->user)->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('Deal with now')
            ->assertDontSee('Deal with later');

        $this->assertNotNull($email->fresh()?->snoozed_until);
        $this->assertNull($other->fresh()?->snoozed_until);
    }

    public function test_it_snoozes_the_whole_conversation(): void
    {
        $first = $this->email(['thread_id' => 'thread-a', 'subject' => 'Original']);
        $reply = $this->email(['thread_id' => 'thread-a', 'subject' => 'Re: Original']);
        $unrelated = $this->email(['thread_id' => 'thread-b']);

        $this->snooze($first, ['preset' => SnoozePresets::TOMORROW]);

        $this->assertNotNull($first->fresh()?->snoozed_until);
        $this->assertNotNull($reply->fresh()?->snoozed_until);
        $this->assertNull($unrelated->fresh()?->snoozed_until);
    }

    public function test_a_snoozed_conversation_leaves_triage(): void
    {
        $email = $this->email(['subject' => 'Put off']);
        $this->email(['subject' => 'Still here']);

        $this->snooze($email, ['preset' => SnoozePresets::TOMORROW]);

        $this->actingAs($this->user)->get(route('triage.index', ['account' => $this->account->id]))
            ->assertOk()
            ->assertDontSee('Put off');
    }

    public function test_it_does_not_count_towards_the_unread_badge(): void
    {
        $email = $this->email();
        $this->email();

        $before = $this->actingAs($this->user)->getJson(route('inbox.unreadCount'))->json('unread');

        $this->snooze($email, ['preset' => SnoozePresets::TOMORROW]);

        $after = $this->actingAs($this->user)->getJson(route('inbox.unreadCount'))->json('unread');

        $this->assertSame(2, $before);
        $this->assertSame(1, $after);
    }

    public function test_it_returns_to_the_inbox_when_its_time_arrives(): void
    {
        $email = $this->email(['subject' => 'Back later']);

        $this->snooze($email, ['until' => CarbonImmutable::now()->addHours(2)->format('Y-m-d\TH:i')]);

        $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()->assertDontSee('Back later');

        $this->travel(3)->hours();

        // No sweep has run: the query itself treats an expired snooze as due,
        // so the mail is never held hostage by a missed scheduled run.
        $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()->assertSee('Back later');
    }

    public function test_the_sweep_clears_expired_snoozes_and_is_idempotent(): void
    {
        $due = $this->email();
        $notDue = $this->email();

        $this->snooze($due, ['preset' => SnoozePresets::TOMORROW]);
        $this->snooze($notDue, ['preset' => SnoozePresets::NEXT_WEEK]);

        $this->travel(2)->days();

        $this->artisan('mail:wake-snoozed')->assertSuccessful();

        $this->assertNull($due->fresh()?->snoozed_until);
        $this->assertNotNull($notDue->fresh()?->snoozed_until);

        // Running it again finds nothing rather than doing something twice.
        $this->artisan('mail:wake-snoozed')->expectsOutput('Nothing due.')->assertSuccessful();
    }

    /**
     * The sweep selects on the same condition it clears, so being asleep for
     * a while means catching up rather than skipping what fell due meanwhile.
     */
    public function test_a_missed_sweep_catches_up_rather_than_skipping(): void
    {
        foreach (range(1, 3) as $i) {
            $this->snooze($this->email(), ['until' => CarbonImmutable::now()->addDays($i)->format('Y-m-d\TH:i')]);
        }

        $this->travel(10)->days();

        $this->artisan('mail:wake-snoozed')->assertSuccessful();

        $this->assertSame(0, Email::whereNotNull('snoozed_until')->count());
    }

    public function test_it_refuses_a_time_in_the_past(): void
    {
        $email = $this->email();

        $this->snooze($email, ['until' => CarbonImmutable::now()->subHour()->format('Y-m-d\TH:i')])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($email->fresh()?->snoozed_until);
    }

    public function test_it_refuses_an_unparseable_time(): void
    {
        $email = $this->email();

        $this->snooze($email, ['until' => 'whenever'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($email->fresh()?->snoozed_until);
    }

    public function test_the_snoozed_view_lists_it_with_when_it_returns(): void
    {
        $email = $this->email(['subject' => 'Coming back']);
        $this->email(['subject' => 'Never snoozed']);

        $this->snooze($email, ['preset' => SnoozePresets::NEXT_WEEK]);

        $this->actingAs($this->user)->get(route('snoozed.index'))
            ->assertOk()
            ->assertSee('Coming back')
            ->assertDontSee('Never snoozed')
            ->assertSee('Back ');
    }

    public function test_unsnoozing_puts_it_straight_back(): void
    {
        $email = $this->email(['subject' => 'Changed my mind']);

        $this->snooze($email, ['preset' => SnoozePresets::NEXT_WEEK]);

        $this->actingAs($this->user)->from(route('snoozed.index'))
            ->delete(route('inbox.unsnooze', $email))
            ->assertRedirect(route('snoozed.index'));

        $this->assertNull($email->fresh()?->snoozed_until);
        $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()->assertSee('Changed my mind');
    }

    public function test_another_user_cannot_snooze_or_unsnooze_your_mail(): void
    {
        $email = $this->email();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('inbox.snooze', $email), ['preset' => SnoozePresets::TOMORROW])
            ->assertForbidden();
        $this->actingAs($stranger)->delete(route('inbox.unsnooze', $email))->assertForbidden();

        $this->assertNull($email->fresh()?->snoozed_until);
    }

    public function test_the_snoozed_view_shows_only_your_own(): void
    {
        $theirAccount = MailAccount::factory()->create(['user_id' => User::factory()]);
        Email::factory()->create([
            'mail_account_id' => $theirAccount->id,
            'subject' => 'Not yours',
            'folder' => 'INBOX',
            'uid' => '9999',
            'is_deleted' => false,
            'snoozed_until' => CarbonImmutable::now()->addWeek(),
        ]);

        $mine = $this->email(['subject' => 'Mine']);
        $this->snooze($mine, ['preset' => SnoozePresets::NEXT_WEEK]);

        $this->actingAs($this->user)->get(route('snoozed.index'))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Not yours');
    }
}
