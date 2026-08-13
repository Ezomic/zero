<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\MailAccount;
use App\Models\PendingMirrorAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SenderViewTest extends TestCase
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

    private function seedFrom(string $address, int $count = 1, array $attributes = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            Email::factory()->create([
                'mail_account_id' => $this->account->id,
                'from_address' => $address,
                'folder' => 'INBOX',
                'uid' => (string) fake()->unique()->numberBetween(1, 999999),
                'is_deleted' => false,
                'is_archived' => false,
                'is_read' => false,
                ...$attributes,
            ]);
        }
    }

    private function visit(string $address)
    {
        return $this->actingAs($this->user)->get(route('sender.show', ['address' => $address]));
    }

    private function bulk(string $address, string $action, array $extra = [])
    {
        return $this->actingAs($this->user)
            ->from(route('sender.show', ['address' => $address]))
            ->post(route('sender.bulk'), ['address' => $address, 'action' => $action, ...$extra]);
    }

    public function test_it_lists_everything_from_the_sender(): void
    {
        $this->seedFrom('news@example.com', 3);
        $this->seedFrom('someone@else.com', 2);

        $this->visit('news@example.com')
            ->assertOk()
            ->assertSee('news@example.com')
            ->assertSee('3 messages');
    }

    public function test_the_address_match_is_case_insensitive(): void
    {
        $this->seedFrom('News@Example.com', 2);

        $this->visit('news@example.com')->assertOk()->assertSee('2 messages');
    }

    public function test_it_shows_nobody_elses_mail(): void
    {
        $other = MailAccount::factory()->create(['user_id' => User::factory()]);
        Email::factory()->create([
            'mail_account_id' => $other->id,
            'from_address' => 'news@example.com',
            'subject' => 'Somebody elses newsletter',
        ]);
        $this->seedFrom('news@example.com', 1, ['subject' => 'My own newsletter']);

        $this->visit('news@example.com')
            ->assertOk()
            ->assertSee('1 message')
            ->assertDontSee('Somebody elses newsletter');
    }

    public function test_a_nonsense_address_is_not_found(): void
    {
        $this->actingAs($this->user)->get(route('sender.show', ['address' => 'not-an-address']))->assertNotFound();
    }

    /**
     * The point of the view: acting on everything, not the visible page.
     */
    public function test_archiving_applies_beyond_the_first_page(): void
    {
        $this->seedFrom('news@example.com', 30);

        $this->bulk('news@example.com', 'archive')->assertRedirect();

        $this->assertSame(30, Email::where('from_address', 'news@example.com')->where('is_archived', true)->count());
    }

    public function test_marking_read_mirrors_every_message(): void
    {
        $this->seedFrom('news@example.com', 4);

        $this->bulk('news@example.com', 'read')->assertRedirect();

        $this->assertSame(0, Email::where('from_address', 'news@example.com')->where('is_read', false)->count());
        $this->assertSame(4, PendingMirrorAction::where('action', 'mark_read')->count());
    }

    public function test_marking_read_skips_what_is_already_read(): void
    {
        $this->seedFrom('news@example.com', 2, ['is_read' => true]);
        $this->seedFrom('news@example.com', 3);

        $this->bulk('news@example.com', 'read')->assertRedirect();

        // Only the three genuinely unread ones needed telling the server.
        $this->assertSame(3, PendingMirrorAction::where('action', 'mark_read')->count());
    }

    public function test_a_small_delete_goes_through_without_a_ceremony(): void
    {
        $this->seedFrom('news@example.com', 5);

        $this->bulk('news@example.com', 'delete')->assertRedirect()->assertSessionHas('status');

        $this->assertSame(5, Email::where('from_address', 'news@example.com')->where('is_deleted', true)->count());
        $this->assertSame(5, PendingMirrorAction::where('action', 'delete')->count());
    }

    /**
     * Deleting a whole sender's history on one click should be asked about
     * rather than discovered afterwards.
     */
    public function test_a_large_delete_is_refused_until_confirmed(): void
    {
        $this->seedFrom('news@example.com', 40);

        $this->bulk('news@example.com', 'delete')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Email::where('from_address', 'news@example.com')->where('is_deleted', true)->count());
    }

    public function test_a_large_delete_proceeds_once_confirmed(): void
    {
        $this->seedFrom('news@example.com', 40);

        $this->bulk('news@example.com', 'delete', ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(40, Email::where('from_address', 'news@example.com')->where('is_deleted', true)->count());
    }

    public function test_a_bulk_action_never_reaches_another_users_mail(): void
    {
        $other = MailAccount::factory()->create(['user_id' => User::factory()]);
        $theirs = Email::factory()->create([
            'mail_account_id' => $other->id,
            'from_address' => 'news@example.com',
            'is_archived' => false,
        ]);
        $this->seedFrom('news@example.com', 2);

        $this->bulk('news@example.com', 'archive')->assertRedirect();

        $this->assertFalse($theirs->refresh()->is_archived);
    }

    public function test_acting_on_a_sender_with_nothing_says_so(): void
    {
        $this->bulk('ghost@example.com', 'archive')
            ->assertRedirect()
            ->assertSessionHas('status', 'Nothing from that sender.');
    }

    public function test_the_reading_pane_links_to_the_sender(): void
    {
        $this->seedFrom('news@example.com', 1);
        $email = Email::where('from_address', 'news@example.com')->sole();

        $this->actingAs($this->user)
            ->get(route('inbox.panel', $email))
            ->assertOk()
            ->assertSee(route('sender.show', ['address' => 'news@example.com']), false);
    }
}
